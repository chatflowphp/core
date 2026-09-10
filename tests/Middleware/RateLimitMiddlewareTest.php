<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Middleware;

use ChatFlow\Core\Context;
use ChatFlow\Middleware\RateLimitMiddleware;
use ChatFlow\Storage\Drivers\FileStorage;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Storage\StorageInterface;
use ChatFlow\Tests\Support\FakePlatformAdapter;
use ChatFlow\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimitMiddlewareTest extends TestCase
{
    /**
     * @param callable(): StorageInterface $factory
     */
    #[DataProvider('storages')]
    public function testRequestsBeyondTheLimitAreRejectedOnEveryDriver(callable $factory): void
    {
        $storage = $factory();
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter);
        $application->middleware([new RateLimitMiddleware($storage, maxRequests: 2, windowSeconds: 60, rejectionMessage: 'Slow down')]);
        $hits = 0;
        $application->onCommand('ping', static function () use (&$hits): void {
            $hits++;
        });

        $results = [];

        for ($i = 0; $i < 4; $i++) {
            $results[] = $application->handle(TestApp::event('c', '/ping'))->getStatus();
        }

        self::assertSame(2, $hits);
        self::assertSame(['success', 'success', 'error', 'error'], $results);
        self::assertSame(['Slow down', 'Slow down'], array_map(static fn($view) => $view->getText(), $adapter->replies));
        self::assertSame(4, $storage->get(RateLimitMiddleware::KEY_PREFIX . '1')['count'] ?? null);
    }

    /**
     * @return iterable<string, array{callable(): StorageInterface}>
     */
    public static function storages(): iterable
    {
        yield 'memory' => [static fn(): StorageInterface => new MemoryStorage()];
        yield 'file' => [static fn(): StorageInterface => new FileStorage(sys_get_temp_dir() . '/chatflow-rate-limit-' . bin2hex(random_bytes(4)))];
    }

    public function testExpiredWindowsRestartTheCounter(): void
    {
        $storage = new MemoryStorage();
        $storage->save(RateLimitMiddleware::KEY_PREFIX . '1', ['count' => 99, 'reset_at' => time() - 1]);
        $application = TestApp::create();
        $application->middleware([new RateLimitMiddleware($storage, maxRequests: 1)]);
        $application->onCommand('ping', static fn(): null => null);

        self::assertTrue($application->handle(TestApp::event('c', '/ping'))->isSuccess());
    }

    public function testEventsWithoutAUserAreNotLimited(): void
    {
        $application = TestApp::create();
        $application->middleware([new RateLimitMiddleware(new MemoryStorage(), maxRequests: 0)]);
        $application->onCommand('ping', static fn(): null => null);

        self::assertTrue($application->handle(TestApp::event('c', '/ping', userId: null))->isSuccess());
    }

    public function testCorruptedRecordsAreIgnored(): void
    {
        $storage = new MemoryStorage();
        $storage->save(RateLimitMiddleware::KEY_PREFIX . '1', ['count' => 'many']);
        $application = TestApp::create();
        $application->middleware([new RateLimitMiddleware($storage, maxRequests: 1)]);
        $application->onCommand('ping', static fn(Context $ctx): null => null);

        self::assertTrue($application->handle(TestApp::event('c', '/ping'))->isSuccess());
    }
}
