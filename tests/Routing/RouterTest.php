<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Routing;

use ChatFlow\Routing\Route;
use ChatFlow\Routing\Router;
use ChatFlow\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testFirstRegisteredRouteWins(): void
    {
        $router = new Router();
        $handler = static fn(): null => null;
        $prefix = $router->onTextPrefix('/settings', $handler);
        $command = $router->onCommand('start', $handler);
        $fallback = $router->fallback($handler);

        self::assertSame($prefix, $router->match(TestApp::event('c', '/settings now')));
        self::assertSame($command, $router->match(TestApp::event('c', '/start@bot')));
        self::assertSame($fallback, $router->match(TestApp::event('c', 'anything')));
        self::assertSame([$prefix, $command, $fallback], $router->getRoutes());

        $router->clear();
        self::assertNull($router->match(TestApp::event('c', '/start')));
    }

    public function testCustomRoutesCanBeAdded(): void
    {
        $router = new Router();
        $route = $router->add(Route::custom('always', static fn(): null => null));

        self::assertSame($route, $router->match(TestApp::event('c')));
    }
}
