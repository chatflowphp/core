<?php

declare(strict_types=1);

namespace ChatFlow\Core;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Contracts\PlatformAdapterInterface;
use ChatFlow\Contracts\RuntimeDependencyBinderInterface;
use ChatFlow\Exception\ErrorHandlerInterface;
use ChatFlow\FSM\BaseScene;
use ChatFlow\FSM\StateManager;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Middleware\Pipeline;
use ChatFlow\Observability\NullRuntimeObserver;
use ChatFlow\Observability\RuntimeEvent;
use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Routing\Route;
use ChatFlow\Routing\Router;
use ChatFlow\Validation\ValidationRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class Application
{
    /** @var array<MiddlewareInterface|class-string<MiddlewareInterface>> */
    private array $middlewares = [];

    public function __construct(
        private readonly PlatformAdapterInterface $adapter,
        private readonly Router $router,
        private readonly ContainerInterface $container,
        private readonly ErrorHandlerInterface $errorHandler,
        private readonly ValidationRegistry $validationRegistry,
        private readonly ?StateManager $stateManager = null,
        ?LoggerInterface $logger = null,
        ?RuntimeObserverInterface $runtimeObserver = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->runtimeObserver = $runtimeObserver ?? new NullRuntimeObserver();
        $this->container->set(ValidationRegistry::class, $this->validationRegistry);
    }

    private LoggerInterface $logger;

    private RuntimeObserverInterface $runtimeObserver;

    public function handle(InboundEventInterface $event): Result
    {
        $context = new Context($event, $this->adapter, $this->container, $this->stateManager, runtimeObserver: $this->runtimeObserver);
        $result = Result::noMatch();
        $this->record('inbound.received', $event->getConversationId(), [
            'is_action' => $event->isAction(),
            'action_id' => $event->getActionId(),
            'text' => $event->getText(),
        ]);

        try {
            $this->bindRuntimeDependencies($context);

            if ($this->stateManager !== null) {
                $session = $this->stateManager->loadSession($event->getConversationId());
                $context->setSession($session);
                $this->stateManager->applyPendingTransitions($context);
            }

            $target = $this->determineExecutionTarget($context);
            $this->recordTargetMatched($context, $target);
            $pipeline = new Pipeline($this->container);

            /** @var Result $result */
            $result = $pipeline
                ->send($context)
                ->through($this->collectMiddlewareStack($target))
                ->then(fn (Context $ctx): Result => $this->executeTarget($ctx, $target));

            if ($this->stateManager !== null && $context->getSession() !== null) {
                $this->stateManager->saveSession($context->session());
            }

            return $this->flushOutboundEffects($context, $result);
        } catch (Throwable $exception) {
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
                Result::error($exception->getMessage(), ['exception' => $exception::class])
            );
        } finally {
            $this->container->flush();
        }
    }

    /**
     * @param array<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     */
    public function middleware(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->middlewares[] = $middleware;
        }

        return $this;
    }

    public function onTextPrefix(string $prefix, callable $handler): Route
    {
        return $this->router->onTextPrefix($prefix, $handler);
    }

    public function onActionPrefix(string $prefix, callable $handler): Route
    {
        return $this->router->onActionPrefix($prefix, $handler);
    }

    public function onTextRegex(string $pattern, callable $handler): Route
    {
        return $this->router->onTextRegex($pattern, $handler);
    }

    public function onCommand(string $command, callable $handler): Route
    {
        return $this->router->onCommand($command, $handler);
    }

    public function onAction(string $action, callable $handler): Route
    {
        return $this->router->onAction($action, $handler);
    }

    public function onActionRegex(string $pattern, callable $handler): Route
    {
        return $this->router->onActionRegex($pattern, $handler);
    }

    public function fallback(callable $handler): Route
    {
        return $this->router->fallback($handler);
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getStateManager(): ?StateManager
    {
        return $this->stateManager;
    }

    public function getAdapter(): PlatformAdapterInterface
    {
        return $this->adapter;
    }

    /**
     * @param array{type: 'scene'|'route'|'none', data: mixed} $target
     *
     * @return array<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    private function collectMiddlewareStack(array $target): array
    {
        $stack = $this->middlewares;

        if ($target['type'] === 'route') {
            /** @var Route $route */
            $route = $target['data'];

            return [...$stack, ...$route->getMiddlewares()];
        }

        if ($target['type'] === 'scene' && $this->stateManager !== null) {
            /** @var string $sceneClass */
            $sceneClass = $target['data'];
            $scene = $this->stateManager->getRegistry()->get($sceneClass);

            return [...$stack, ...$scene->getMiddlewares()];
        }

        return $stack;
    }

    /**
     * @return array{type: 'scene'|'route'|'none', data: mixed}
     */
    private function determineExecutionTarget(Context $context): array
    {
        $activeScene = $context->getSession()?->getCurrentScene();
        if ($activeScene !== null) {
            return ['type' => 'scene', 'data' => $activeScene];
        }

        $route = $this->router->match($context->getEvent());
        if ($route !== null) {
            return ['type' => 'route', 'data' => $route];
        }

        return ['type' => 'none', 'data' => null];
    }

    /**
     * @param array{type: 'scene'|'route'|'none', data: mixed} $target
     */
    private function executeTarget(Context $context, array $target): Result
    {
        if ($target['type'] === 'scene') {
            if ($this->stateManager === null) {
                return Result::error('Scene processing is unavailable without a state manager.');
            }

            /** @var string $sceneClass */
            $sceneClass = $target['data'];
            /** @var BaseScene $scene */
            $scene = $this->stateManager->getRegistry()->get($sceneClass);

            return $this->stateManager->processScene($context, false, $scene)
                ? Result::success('scene_processed')
                : Result::noMatch('scene_inactive');
        }

        if ($target['type'] === 'route') {
            /** @var Route $route */
            $route = $target['data'];
            $this->container->call($route->getHandler(), [
                Context::class => $context,
                InboundEventInterface::class => $context->getEvent(),
                'ctx' => $context,
                'context' => $context,
                'event' => $context->getEvent(),
            ]);

            return Result::success('route_processed');
        }

        return Result::noMatch();
    }

    private function bindRuntimeDependencies(Context $context): void
    {
        $this->container->set(Context::class, $context);
        $this->container->set(InboundEventInterface::class, $context->getEvent());

        if ($this->stateManager !== null) {
            $this->container->set(StateManager::class, $this->stateManager);
        }

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
                $this->logger->error('Outbound delivery failed', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'effect' => $effect->getType(),
                    'conversation_id' => $context->getConversationId(),
                ]);

                $context->clearOutboundEffects();
                $this->record('delivery.failed', $context->getConversationId(), [
                    'effect' => $effect->getType(),
                    'reason' => $exception->getMessage(),
                ]);

                return Result::error('delivery_failed', [
                    'reason' => $exception->getMessage(),
                    'effect' => $effect->getType(),
                ]);
            }

            if ($delivery->isError()) {
                $this->logger->error('Outbound delivery failed', [
                    'message' => $delivery->getMessage(),
                    'effect' => $effect->getType(),
                    'conversation_id' => $context->getConversationId(),
                ]);

                $context->clearOutboundEffects();
                $this->record('delivery.failed', $context->getConversationId(), [
                    'effect' => $effect->getType(),
                    'reason' => $delivery->getMessage(),
                ]);

                return Result::error('delivery_failed', [
                    'reason' => $delivery->getMessage(),
                    'effect' => $effect->getType(),
                    'data' => $delivery->getData(),
                ]);
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
     * @param array{type: 'scene'|'route'|'none', data: mixed} $target
     */
    private function recordTargetMatched(Context $context, array $target): void
    {
        if ($target['type'] === 'route') {
            /** @var Route $route */
            $route = $target['data'];
            $this->record('route.matched', $context->getConversationId(), [
                'route_type' => $route->getType(),
                'prefix' => $route->getPrefix(),
            ]);

            return;
        }

        if ($target['type'] === 'scene') {
            $this->record('scene.matched', $context->getConversationId(), [
                'scene' => is_string($target['data']) ? $target['data'] : null,
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
