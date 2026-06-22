<?php

declare(strict_types=1);

namespace ChatFlow\Tests;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\FlowInterface;
use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Core\Context;
use ChatFlow\FSM\BaseScene;
use ChatFlow\FSM\StateManager;
use ChatFlow\Routing\Route;
use ChatFlow\Routing\Router;
use ChatFlow\Tests\Fixtures\SurveyScene;
use ChatFlow\Validation\ValidationRegistry;
use PHPUnit\Framework\TestCase;
use Throwable;

final class FlowContractTest extends TestCase
{
    public function test_flow_interface_registers_against_runtime_contract(): void
    {
        $runtime = new TraceFlowRuntime($this->createMock(ContainerInterface::class));
        $flow = new class () implements FlowInterface {
            public function register(FlowRuntimeInterface $runtime): void
            {
                $runtime->registerScene(SurveyScene::class);
                $runtime->middleware([]);
                $runtime->onCommand('start', static function (Context $ctx): void {
                    $ctx->reply('started');
                });
                $runtime->onActionPrefix('survey:', static function (): void {
                });
                $runtime->onException(\RuntimeException::class, static function (): void {
                });
                $runtime->setErrorHandler(static function (): void {
                });
            }
        };

        $flow->register($runtime);

        self::assertSame([SurveyScene::class], $runtime->sceneClasses);
        self::assertSame(['command:start', 'action_prefix:survey:'], $runtime->routes);
        self::assertSame([\RuntimeException::class], $runtime->exceptionClasses);
        self::assertTrue($runtime->errorHandlerRegistered);
    }
}

final class TraceFlowRuntime implements FlowRuntimeInterface
{
    /** @var list<class-string<BaseScene>> */
    public array $sceneClasses = [];

    /** @var list<string> */
    public array $routes = [];

    /** @var list<class-string<Throwable>> */
    public array $exceptionClasses = [];

    public bool $errorHandlerRegistered = false;

    private Router $router;

    private ValidationRegistry $validationRegistry;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->router = new Router();
        $this->validationRegistry = new ValidationRegistry($container);
    }

    public function onTextPrefix(string $prefix, callable $handler): Route
    {
        $this->routes[] = 'text_prefix:' . $prefix;

        return $this->router->onTextPrefix($prefix, $handler);
    }

    public function onTextRegex(string $pattern, callable $handler): Route
    {
        $this->routes[] = 'text_regex:' . $pattern;

        return $this->router->onTextRegex($pattern, $handler);
    }

    public function onCommand(string $command, callable $handler): Route
    {
        $this->routes[] = 'command:' . $command;

        return $this->router->onCommand($command, $handler);
    }

    public function onAction(string $action, callable $handler): Route
    {
        $this->routes[] = 'action:' . $action;

        return $this->router->onAction($action, $handler);
    }

    public function onActionPrefix(string $prefix, callable $handler): Route
    {
        $this->routes[] = 'action_prefix:' . $prefix;

        return $this->router->onActionPrefix($prefix, $handler);
    }

    public function onActionRegex(string $pattern, callable $handler): Route
    {
        $this->routes[] = 'action_regex:' . $pattern;

        return $this->router->onActionRegex($pattern, $handler);
    }

    public function fallback(callable $handler): Route
    {
        $this->routes[] = 'fallback';

        return $this->router->fallback($handler);
    }

    public function registerScene(string $sceneClass, ?string $label = null): self
    {
        $this->sceneClasses[] = $sceneClass;

        return $this;
    }

    public function middleware(array $middlewares): self
    {
        return $this;
    }

    public function setErrorHandler(callable $handler): self
    {
        $this->errorHandlerRegistered = true;

        return $this;
    }

    public function onException(string $exception, callable $handler): self
    {
        $this->exceptionClasses[] = $exception;

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

    public function getStateManager(): ?StateManager
    {
        return null;
    }
}
