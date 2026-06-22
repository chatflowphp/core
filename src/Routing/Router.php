<?php

declare(strict_types=1);

namespace ChatFlow\Routing;

use ChatFlow\Contracts\InboundEventInterface;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    public function onTextPrefix(string $prefix, callable $handler): Route
    {
        $route = new Route('text_prefix', $prefix, $handler);
        $this->routes[] = $route;

        return $route;
    }

    public function onTextRegex(string $pattern, callable $handler): Route
    {
        $route = new Route('text_regex', $pattern, $handler);
        $this->routes[] = $route;

        return $route;
    }

    public function onCommand(string $command, callable $handler): Route
    {
        $route = new Route('command', ltrim($command, '/'), $handler);
        $this->routes[] = $route;

        return $route;
    }

    public function onAction(string $action, callable $handler): Route
    {
        $route = new Route('action', $action, $handler);
        $this->routes[] = $route;

        return $route;
    }

    public function onActionPrefix(string $prefix, callable $handler): Route
    {
        $route = new Route('action_prefix', $prefix, $handler);
        $this->routes[] = $route;

        return $route;
    }

    public function onActionRegex(string $pattern, callable $handler): Route
    {
        $route = new Route('action_regex', $pattern, $handler);
        $this->routes[] = $route;

        return $route;
    }

    public function fallback(callable $handler): Route
    {
        $route = new Route('fallback', '', $handler);
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
