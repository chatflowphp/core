<?php

declare(strict_types=1);

namespace ChatFlow\Contracts;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Core\Context;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Routing\Route;
use ChatFlow\Scene\BaseScene;
use ChatFlow\Scene\SceneContext;
use ChatFlow\Scene\SceneRegistry;
use ChatFlow\Scene\SceneTransitions;
use ChatFlow\Validation\ValidationRegistry;
use Throwable;

/**
 * What a flow needs from a runtime to register itself. Implemented by the core Application and by
 * adapter facades such as the Telegram Bot.
 */
interface FlowRuntimeInterface
{
    public function onCommand(string $command, callable $handler): Route;

    public function onTextPrefix(string $prefix, callable $handler): Route;

    public function onTextRegex(string $pattern, callable $handler): Route;

    public function onAction(string $action, callable $handler): Route;

    public function onActionPrefix(string $prefix, callable $handler): Route;

    public function onActionRegex(string $pattern, callable $handler): Route;

    public function fallback(callable $handler): Route;

    /**
     * @param class-string<BaseScene> $sceneClass
     */
    public function registerScene(string $sceneClass, ?string $label = null): static;

    /**
     * Restricts scene transitions. Scenes are referenced by class or id; SceneTransitions::ANY
     * stands for any source scene.
     *
     * @param (callable(SceneContext): bool)|null $guard
     */
    public function allowTransition(string $from, string $to, ?callable $guard = null): static;

    /**
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     */
    public function middleware(array $middlewares): static;

    /**
     * @param callable(Throwable, Context): void $handler
     */
    public function setErrorHandler(callable $handler): static;

    /**
     * @param class-string<Throwable> $exception
     * @param callable(Throwable, Context|null): void $handler
     */
    public function onException(string $exception, callable $handler): static;

    public function getContainer(): ContainerInterface;

    public function getValidationRegistry(): ValidationRegistry;

    public function getScenes(): SceneRegistry;

    public function getTransitions(): SceneTransitions;
}
