<?php

declare(strict_types=1);

namespace ChatFlow\Contracts;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\FSM\BaseScene;
use ChatFlow\FSM\StateManager;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Routing\Route;
use ChatFlow\Validation\ValidationRegistry;
use Throwable;

interface FlowRuntimeInterface
{
    public function onTextPrefix(string $prefix, callable $handler): Route;

    public function onTextRegex(string $pattern, callable $handler): Route;

    public function onCommand(string $command, callable $handler): Route;

    public function onAction(string $action, callable $handler): Route;

    public function onActionPrefix(string $prefix, callable $handler): Route;

    public function onActionRegex(string $pattern, callable $handler): Route;

    public function fallback(callable $handler): Route;

    /**
     * @param class-string<BaseScene> $sceneClass
     */
    public function registerScene(string $sceneClass, ?string $label = null): self;

    /**
     * @param array<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     */
    public function middleware(array $middlewares): self;

    /**
     * @param callable(Throwable, \ChatFlow\Core\Context): void $handler
     */
    public function setErrorHandler(callable $handler): self;

    /**
     * @param class-string<Throwable>                            $exception
     * @param callable(Throwable, ?\ChatFlow\Core\Context): void $handler
     */
    public function onException(string $exception, callable $handler): self;

    public function getContainer(): ContainerInterface;

    public function getValidationRegistry(): ValidationRegistry;

    public function getStateManager(): ?StateManager;
}
