<?php

declare(strict_types=1);

namespace ChatFlow\Core;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Contracts\PlatformAdapterInterface;
use ChatFlow\Contracts\RuntimeDependencyBinderInterface;
use ChatFlow\Exception\ErrorHandlerInterface;
use ChatFlow\Exception\ExceptionRegistry;
use ChatFlow\Exception\LogicException;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Middleware\Pipeline;
use ChatFlow\Observability\NullRuntimeObserver;
use ChatFlow\Observability\RuntimeEvent;
use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Routing\Route;
use ChatFlow\Routing\Router;
use ChatFlow\Scene\Conversation;
use ChatFlow\Scene\ConversationManager;
use ChatFlow\Scene\Events\RouteHandled;
use ChatFlow\Scene\Events\RouteMissed;
use ChatFlow\Scene\RootScene;
use ChatFlow\Scene\SceneRegistry;
use ChatFlow\Scene\SceneTransitions;
use ChatFlow\Validation\ValidationRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The runtime: one inbound event becomes exactly one tick of the conversation's state machine.
 *
 * Order of work for every event: bind request dependencies, resume the conversation, match a
 * route, run middleware around the tick, persist the conversation, deliver queued effects. When
 * anything fails the conversation is rolled back, queued effects are dropped and only the error
 * handler gets to reply.
 */
class Application implements FlowRuntimeInterface
{
    /**
     * @var list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    private array $middlewares = [];

    private readonly Router $router;

    private readonly ConversationManager $conversations;

    private readonly ErrorHandlerInterface $errorHandler;

    private readonly ValidationRegistry $validationRegistry;

    private readonly LoggerInterface $logger;

    private readonly RuntimeObserverInterface $runtimeObserver;

    public function __construct(
        private readonly PlatformAdapterInterface $adapter,
        private readonly ContainerInterface $container,
        ?Router $router = null,
        ?ConversationManager $conversations = null,
        ?ErrorHandlerInterface $errorHandler = null,
        ?ValidationRegistry $validationRegistry = null,
        ?LoggerInterface $logger = null,
        ?RuntimeObserverInterface $runtimeObserver = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->runtimeObserver = $runtimeObserver ?? new NullRuntimeObserver();
        $this->router = $router ?? new Router();
        $this->validationRegistry = $validationRegistry ?? new ValidationRegistry($container);
        $this->errorHandler = $errorHandler ?? new ExceptionRegistry($this->logger);
        $this->conversations = $conversations ?? new ConversationManager(
            new SceneRegistry($container),
            $this->validationRegistry,
            observer: $this->runtimeObserver,
        );

        $this->container->set(ValidationRegistry::class, $this->validationRegistry);
        $this->container->set(SceneRegistry::class, $this->conversations->getScenes());
        $this->container->set(ConversationManager::class, $this->conversations);
    }

    /**
     * @param Route|null $route Handler chosen by the adapter; when given, the router is skipped.
     */
    public function handle(InboundEventInterface $event, ?Route $route = null): Result
    {
        $context = new Context($event, $this->adapter, $this->container, $this->runtimeObserver);
        $this->record('inbound.received', $event->getConversationId(), [
            'is_action' => $event->isAction(),
            'action_id' => $event->getActionId(),
            'text' => $event->getText(),
        ]);

        try {
            $this->bindRuntimeDependencies($context);

            $conversation = $this->conversations->resume($event->getConversationId());
            $context->attachConversation($conversation);

            $route ??= $this->router->match($event);
            $this->recordTarget($context, $conversation, $route);

            $ticked = false;
            $result = (new Pipeline($this->container))
                ->send($context)
                ->through($this->collectMiddlewareStack($conversation, $route))
                ->then(function (Context $ctx) use ($conversation, $route, &$ticked): Result {
                    $ticked = true;

                    return $this->tick($ctx, $conversation, $route);
                });

            if (!$result instanceof Result) {
                throw new LogicException(\sprintf('Middleware must return %s, got %s.', Result::class, get_debug_type($result)));
            }

            if ($ticked && self::shouldPersist($conversation)) {
                $conversation->persist();
            }

            return $this->flushOutboundEffects($context, $result);
        } catch (Throwable $exception) {
            $context->clearOutboundEffects();
            $this->record('handler.failed', $context->getConversationId(), [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->logger->error('Application handling failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'conversation_id' => $context->getConversationId(),
            ]);
            $this->errorHandler->handle($exception, $context);

            return $this->flushOutboundEffects(
                $context,
                Result::error($exception->getMessage(), ['exception' => $exception::class]),
            );
        } finally {
            $this->container->flush();
        }
    }

    // -- FlowRuntimeInterface ------------------------------------------------------------------

    public function onCommand(string $command, callable $handler): Route
    {
        return $this->router->onCommand($command, $handler);
    }

    public function onTextPrefix(string $prefix, callable $handler): Route
    {
        return $this->router->onTextPrefix($prefix, $handler);
    }

    public function onTextRegex(string $pattern, callable $handler): Route
    {
        return $this->router->onTextRegex($pattern, $handler);
    }

    public function onAction(string $action, callable $handler): Route
    {
        return $this->router->onAction($action, $handler);
    }

    public function onActionPrefix(string $prefix, callable $handler): Route
    {
        return $this->router->onActionPrefix($prefix, $handler);
    }

    public function onActionRegex(string $pattern, callable $handler): Route
    {
        return $this->router->onActionRegex($pattern, $handler);
    }

    public function fallback(callable $handler): Route
    {
        return $this->router->fallback($handler);
    }

    public function registerScene(string $sceneClass, ?string $label = null): static
    {
        $this->conversations->getScenes()->register($sceneClass, $label);

        return $this;
    }

    public function allowTransition(string $from, string $to, ?callable $guard = null): static
    {
        $this->conversations->getTransitions()->allow($from, $to, $guard);

        return $this;
    }

    public function middleware(array $middlewares): static
    {
        foreach ($middlewares as $middleware) {
            $this->middlewares[] = $middleware;
        }

        return $this;
    }

    public function setErrorHandler(callable $handler): static
    {
        $this->errorHandler->register(Throwable::class, static function (Throwable $exception, ?Context $context) use ($handler): void {
            if ($context !== null) {
                $handler($exception, $context);
            }
        });

        return $this;
    }

    public function onException(string $exception, callable $handler): static
    {
        $this->errorHandler->register($exception, $handler);

        return $this;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getValidationRegistry(): ValidationRegistry
    {
        return $this->validationRegistry;
    }

    public function getScenes(): SceneRegistry
    {
        return $this->conversations->getScenes();
    }

    public function getTransitions(): SceneTransitions
    {
        return $this->conversations->getTransitions();
    }

    // -- accessors -----------------------------------------------------------------------------

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getConversations(): ConversationManager
    {
        return $this->conversations;
    }

    public function getAdapter(): PlatformAdapterInterface
    {
        return $this->adapter;
    }

    public function getErrorHandler(): ErrorHandlerInterface
    {
        return $this->errorHandler;
    }

    /**
     * @return list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    // -- internals -----------------------------------------------------------------------------

    private function tick(Context $context, Conversation $conversation, ?Route $route): Result
    {
        $result = $conversation->tick($context, $route);

        if ($result->messagesOf(RouteMissed::class) !== []) {
            return Result::noMatch();
        }

        $handled = $result->messagesOf(RouteHandled::class);

        if ($handled !== []) {
            return Result::success($handled[0]->sceneId === RootScene::ID ? 'route_processed' : 'global_route_processed');
        }

        return Result::success('scene_processed');
    }

    /**
     * A conversation that never left the root scene and stored nothing has no snapshot worth
     * writing; this keeps unknown chats from filling the storage.
     */
    private static function shouldPersist(Conversation $conversation): bool
    {
        return !$conversation->isNew()
            || $conversation->inScene()
            || $conversation->getContext()->getState() !== [];
    }

    /**
     * @return list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    private function collectMiddlewareStack(Conversation $conversation, ?Route $route): array
    {
        $stack = $this->middlewares;
        $sceneId = $conversation->getCurrentScene();
        $scene = $sceneId === RootScene::ID ? null : $this->conversations->getScenes()->get($sceneId);

        if ($scene !== null) {
            $stack = [...$stack, ...$scene->getMiddlewares()];
        }

        if ($route !== null && ($scene === null || ($route->isGlobal() && $scene->allowsGlobalRoutes()))) {
            $stack = [...$stack, ...$route->getMiddlewares()];
        }

        return $stack;
    }

    private function bindRuntimeDependencies(Context $context): void
    {
        $this->container->scoped(Context::class, $context);
        $this->container->scoped(InboundEventInterface::class, $context->getEvent());

        if ($this->adapter instanceof RuntimeDependencyBinderInterface) {
            $this->adapter->bindRuntimeDependencies($this->container, $context);
        }
    }

    private function flushOutboundEffects(Context $context, Result $result): Result
    {
        foreach ($context->getOutboundEffects() as $effect) {
            try {
                $delivery = $this->adapter->deliver($context, $effect);
            } catch (Throwable $exception) {
                return $this->deliveryFailed($context, $effect->getType(), $exception->getMessage(), null);
            }

            if ($delivery->isError()) {
                return $this->deliveryFailed($context, $effect->getType(), (string) $delivery->getMessage(), $delivery->getData());
            }

            $this->record('effect.delivered', $context->getConversationId(), [
                'effect' => $effect->getType(),
                'message' => $delivery->getMessage(),
            ]);
        }

        $context->clearOutboundEffects();

        return $result;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function deliveryFailed(Context $context, string $effectType, string $reason, ?array $data): Result
    {
        $context->clearOutboundEffects();
        $this->logger->error('Outbound delivery failed', [
            'message' => $reason,
            'effect' => $effectType,
            'conversation_id' => $context->getConversationId(),
        ]);
        $this->record('delivery.failed', $context->getConversationId(), [
            'effect' => $effectType,
            'reason' => $reason,
        ]);

        return Result::error('delivery_failed', array_filter([
            'reason' => $reason,
            'effect' => $effectType,
            'data' => $data,
        ], static fn(mixed $value): bool => $value !== null));
    }

    private function recordTarget(Context $context, Conversation $conversation, ?Route $route): void
    {
        $sceneId = $conversation->getCurrentScene();

        if ($sceneId !== RootScene::ID) {
            $this->record('scene.matched', $context->getConversationId(), [
                'scene' => $sceneId,
                'global_route' => $route !== null && $route->isGlobal() ? $route->getPattern() : null,
            ]);

            return;
        }

        if ($route !== null) {
            $this->record('route.matched', $context->getConversationId(), [
                'route_type' => $route->getType(),
                'pattern' => $route->getPattern(),
            ]);

            return;
        }

        $this->record('route.missing', $context->getConversationId());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record(string $name, ?string $conversationId, array $data = []): void
    {
        $this->runtimeObserver->record(new RuntimeEvent($name, $conversationId, $data));
    }
}
