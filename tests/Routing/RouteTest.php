<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Routing;

use ChatFlow\Core\Context;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Routing\Route;
use ChatFlow\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    #[DataProvider('commands')]
    public function testCommandMatching(string $text, bool $expected): void
    {
        self::assertSame($expected, Route::matchesCommand('start', $text));
        self::assertSame($expected, Route::matchesCommand('/start', $text));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function commands(): iterable
    {
        yield 'bare' => ['/start', true];
        yield 'with arguments' => ['/start now please', true];
        yield 'group form' => ['/start@my_bot', true];
        yield 'group form with arguments' => ['/start@my_bot 42', true];
        yield 'prefix of another command' => ['/starting', false];
        yield 'not a command' => ['start', false];
        yield 'other command' => ['/stop', false];
        yield 'newline after command' => ["/start\nhello", true];
    }

    public function testEmptyCommandNeverMatches(): void
    {
        self::assertFalse(Route::matchesCommand('/', '/'));
    }

    public function testCommandsAreGlobalByDefaultAndOtherRoutesAreNot(): void
    {
        $handler = static fn(): null => null;

        self::assertTrue((new Route(Route::COMMAND, 'start', $handler))->isGlobal());
        self::assertFalse((new Route(Route::ACTION, 'menu:open', $handler))->isGlobal());
        self::assertTrue((new Route(Route::ACTION, 'menu:open', $handler))->global()->isGlobal());
        self::assertFalse((new Route(Route::COMMAND, 'start', $handler))->global(false)->isGlobal());
        self::assertFalse(Route::custom('media', $handler)->isGlobal());
        self::assertTrue(Route::custom('event', $handler, true)->matches(TestApp::event('c')));
    }

    public function testMatchersForEveryType(): void
    {
        $handler = static fn(): null => null;
        $text = TestApp::event('c', 'hello world');
        $action = TestApp::event('c', actionId: 'cart:add');

        self::assertTrue((new Route(Route::TEXT_PREFIX, 'hello', $handler))->matches($text));
        self::assertFalse((new Route(Route::TEXT_PREFIX, 'world', $handler))->matches($text));
        self::assertTrue((new Route(Route::TEXT_REGEX, '/world$/', $handler))->matches($text));
        self::assertFalse((new Route(Route::TEXT_REGEX, '/[invalid/', $handler))->matches($text));
        self::assertTrue((new Route(Route::ACTION, 'cart:add', $handler))->matches($action));
        self::assertFalse((new Route(Route::ACTION, 'cart:add', $handler))->matches($text));
        self::assertTrue((new Route(Route::ACTION_PREFIX, 'cart:', $handler))->matches($action));
        self::assertTrue((new Route(Route::ACTION_REGEX, '/^cart:/', $handler))->matches($action));
        self::assertTrue((new Route(Route::FALLBACK, '', $handler))->matches($text));
        self::assertFalse((new Route('unknown', '', $handler))->matches($text));
    }

    public function testMiddlewareAccumulates(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function process(Context $ctx, callable $next): mixed
            {
                return $next($ctx);
            }
        };
        $route = (new Route(Route::COMMAND, 'start', static fn(): null => null))
            ->middleware($middleware)
            ->middleware([$middleware::class, $middleware]);

        self::assertSame([$middleware, $middleware::class, $middleware], $route->getMiddlewares());
    }

    #[DataProvider('commandArguments')]
    public function testCommandArgument(string $text, string $expected): void
    {
        self::assertSame($expected, Route::commandArgument('start', $text));
        self::assertSame($expected, Route::commandArgument('/start', $text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commandArguments(): iterable
    {
        yield 'no argument' => ['/start', ''];
        yield 'deep link payload' => ['/start ref_abc123', 'ref_abc123'];
        yield 'group form' => ['/start@my_bot ref_abc123', 'ref_abc123'];
        yield 'several words' => ['/start one two', 'one two'];
        yield 'extra spaces' => ['/start   padded  ', 'padded'];
        yield 'newline' => ["/start\nsecond line", 'second line'];
        yield 'other command' => ['/stop now', ''];
        yield 'not a command' => ['start now', ''];
        yield 'empty command' => ['/', ''];
    }
}
