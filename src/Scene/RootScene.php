<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use Automata\Context\ContextInterface;
use Automata\Machine\CycleRequest;
use Automata\Machine\CycleResponse;
use Automata\State\StateInterface;
use ChatFlow\Routing\RouteDispatcher;
use ChatFlow\Scene\Events\RouteHandled;
use ChatFlow\Scene\Events\RouteMissed;

/**
 * The state a conversation is in when no scene is active. Routes run here.
 */
final class RootScene implements StateInterface
{
    public const ID = 'root';

    public function __construct(private readonly RouteDispatcher $routes) {}

    public function getId(): string
    {
        return self::ID;
    }

    public function onEnter(ContextInterface $context): CycleResponse
    {
        return CycleResponse::none();
    }

    public function process(CycleRequest $request): CycleResponse
    {
        $input = SceneInput::fromRequest($request);
        $route = $input->getRoute();

        if ($route === null) {
            return CycleResponse::fromEvent(new RouteMissed());
        }

        $this->routes->dispatch($route, $input->getContext());

        return CycleResponse::fromEvent(new RouteHandled($route->getType(), $route->getPattern(), $route->isGlobal(), self::ID));
    }

    public function onLeave(ContextInterface $context): void {}
}
