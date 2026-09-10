<?php

declare(strict_types=1);

namespace ChatFlow\Routing;

use ChatFlow\Contracts\InboundEventInterface;

/**
 * Ordered route table. The first matching route wins.
 */
final class Router
{
    /**
     * @var list<Route>
     */
    private array $routes = [];

    public function onCommand(string $command, callable $handler): Route
    {
        return $this->add(new Route(Route::COMMAND, ltrim($command, '/'), $handler));
    }

    public function onTextPrefix(string $prefix, callable $handler): Route
    {
        return $this->add(new Route(Route::TEXT_PREFIX, $prefix, $handler));
    }

    public function onTextRegex(string $pattern, callable $handler): Route
    {
        return $this->add(new Route(Route::TEXT_REGEX, $pattern, $handler));
    }

    public function onAction(string $action, callable $handler): Route
    {
        return $this->add(new Route(Route::ACTION, $action, $handler));
    }

    public function onActionPrefix(string $prefix, callable $handler): Route
    {
        return $this->add(new Route(Route::ACTION_PREFIX, $prefix, $handler));
    }

    public function onActionRegex(string $pattern, callable $handler): Route
    {
        return $this->add(new Route(Route::ACTION_REGEX, $pattern, $handler));
    }

    public function fallback(callable $handler): Route
    {
        return $this->add(new Route(Route::FALLBACK, '', $handler));
    }

    public function add(Route $route): Route
    {
        $this->routes[] = $route;

        return $route;
    }

    public function match(InboundEventInterface $event): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->matches($event)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function clear(): void
    {
        $this->routes = [];
    }
}
