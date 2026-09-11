<?php

declare(strict_types=1);

namespace ChatFlow\Core;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\AfterHandleInterface;
use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Contracts\PlatformAdapterInterface;
use ChatFlow\Contracts\RuntimeDependencyBinderInterface;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\SystemEvent;
use ChatFlow\Exception\ConversationConflictException;
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
use ChatFlow\Scene\SceneContext;
use ChatFlow\Scene\SceneRegistry;
use ChatFlow\Scene\SceneTransitions;
use ChatFlow\Validation\ValidationRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The runtime: one inbound event becomes exactly one tick of the conversation's state machine.
 *
 * Order of work for every event: bind request dependencies, resume the conversation, apply a
 * transition scheduled from outside (its own transaction), match a route, run middleware around
 * the tick, persist the conversation, deliver queued effects. When the tick fails the
 * conversation is rolled back, queued effects are dropped and only the error handler replies.
 *
 * @phpstan-import-type PendingTransition from SceneContext
 */
class Application implements FlowRuntimeInterface
{
    /**
     * @var list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    private array $middlewares = [];

    /**
     * How many times a tick is replayed when another worker wrote the conversation first.
     */
    private const MAX_CONFLICT_ATTEMPTS = 3;

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
        $this->record('inbound.received', $event->getConversationId(), [
            'is_action' => $event->isAction(),
            'action_id' => $event->getActionId(),
            'text' => $event->getText(),
            'system' => $event instanceof SystemEvent,
        ]);

        $attempt = 0;

        while (true) {
            // Every attempt starts from a clean context: nothing is delivered before the snapshot
            // is stored, so a conflicting tick leaves no trace for the user.
            $context = new Context($event, $this->adapter, $this->container, $this->runtimeObserver);

            try {
                $result = $this->process($context, $route);

                break;
            } catch (ConversationConflictException $exception) {
                $attempt++;
                $this->record('conversation.conflict', $event->getConversationId(), [
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);

                if ($attempt >= self::MAX_CONFLICT_ATTEMPTS) {
                    $this->logger->warning('Conversation is being changed by another worker', [
                        'conversation_id' => $event->getConversationId(),
                        'attempts' => $attempt,
                    ]);

                    $result = Result::error('conversation_conflict', ['attempts' => $attempt]);

                    break;
                }
            } catch (Throwable $exception) {
                $result = $this->fail($context, $exception);

                break;
            }
        }

        try {
            if ($this->adapter instanceof AfterHandleInterface) {
                $this->adapter->afterHandle($context, $result);
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Adapter afterHandle hook failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'conversation_id' => $context->getConversationId(),
            ]);
        } finally {
            $this->container->flush();
        }

        return $result;
    }

    /**
     * Runs a handler inside a conversation without an inbound user event: schedulers, admin
     * actions and other chats use it to enter scenes, leave them or send messages through the
     * regular runtime, with middleware, persistence, rollback and delivery.
     *
     * Pass a `ConversationRef` instead of an id when the adapter needs platform facts to deliver
     * the messages, such as the chat a scoped conversation belongs to.
     */
    public function run(string|ConversationRef $conversation, callable $handler, string $reason = 'system'): Result
    {
        $event = $conversation instanceof ConversationRef
            ? new SystemEvent($conversation, reason: $reason)
            : SystemEvent::forConversation($conversation, $reason);

        return $this->handle($event, Route::custom('system:' . $reason, $handler, global: true));
    }

    /**
     * Enters a scene now, from outside of a request. The scene's onEnter() runs and its messages
     * are delivered to the conversation.
     *
     * @param array<string, mixed> $data
     */
    public function enter(string|ConversationRef $conversation, string $scene, array $data = [], ?string $title = null): Result
    {
        return $this->run($conversation, static function (Context $ctx) use ($scene, $data, $title): void {
            $ctx->enter($scene, $data, $title);
        }, 'enter');
    }

    /**
     * Leaves the current scene now, from outside of a request.
     */
    public function leave(string|ConversationRef $conversation): Result
    {
        return $this->run($conversation, static function (Context $ctx): void {
            $ctx->leave();
        }, 'leave');
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

    private function process(Context $context, ?Route $route): Result
    {
        $this->bindRuntimeDependencies($context);

        $conversation = $this->conversations->resume($context->getConversationId());
        $context->attachConversation($conversation);

        $pending = $conversation->takePendingTransition();

        if ($pending !== null) {
            $applied = $this->applyPendingTransition($context, $conversation, $pending);
            $conversation->persist();

            if ($applied !== null) {
                $delivered = $this->flushOutboundEffects($context, $applied);

                if ($delivered->isError() || !$pending['handleTrigger']) {
                    return $delivered;
                }
            }
        }

        $route ??= $this->router->match($context->getEvent());
        $context->bindRoute($route);
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
    }

    private function fail(Context $context, Throwable $exception): Result
    {
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

        try {
            $this->errorHandler->handle($exception, $context);
        } catch (Throwable $handlerException) {
            $context->clearOutboundEffects();
            $this->logger->error('Error handler failed', [
                'exception' => $handlerException::class,
                'message' => $handlerException->getMessage(),
                'conversation_id' => $context->getConversationId(),
            ]);
        }

        return $this->flushOutboundEffects(
            $context,
            Result::error($exception->getMessage(), ['exception' => $exception::class]),
        );
    }

    /**
     * Applies a transition scheduled with enterLater()/leaveLater() as its own transaction, before
     * the event is dispatched. A failed transition is dropped, recorded, and the event is handled
     * as if nothing had been scheduled, so a conversation can never get stuck on it.
     *
     * @param PendingTransition $pending
     *
     * @return Result|null The outcome, or null when the transition failed and normal handling continues.
     */
    private function applyPendingTransition(Context $context, Conversation $conversation, array $pending): ?Result
    {
        try {
            $conversation->withRequest($context, static function () use ($conversation, $pending): void {
                if ($pending['action'] === 'leave') {
                    $conversation->leave();

                    return;
                }

                $conversation->enter((string) $pending['scene'], $pending['data'], $pending['title']);
            });
        } catch (Throwable $exception) {
            $context->clearOutboundEffects();
            $this->record('scene.pending_failed', $context->getConversationId(), [
                'action' => $pending['action'],
                'scene' => $pending['scene'],
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->logger->warning('Pending scene transition failed and was dropped', [
                'conversation_id' => $context->getConversationId(),
                'action' => $pending['action'],
                'scene' => $pending['scene'],
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $this->record('scene.pending_applied', $context->getConversationId(), [
            'action' => $pending['action'],
            'scene' => $pending['scene'],
            'handle_trigger' => $pending['handleTrigger'],
        ]);

        return Result::success($pending['action'] === 'enter' ? 'scene_entered' : 'scene_left');
    }

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
