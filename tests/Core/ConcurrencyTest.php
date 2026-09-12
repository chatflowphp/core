<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Core;

use ChatFlow\Core\Application;
use ChatFlow\Core\Context;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Storage\StorageInterface;
use ChatFlow\Testing\FakePlatformAdapter;
use ChatFlow\Tests\Support\RacingMiddleware;
use ChatFlow\Tests\Support\TestApp;
use ChatFlow\Tests\Support\TraceRuntimeObserver;
use ChatFlow\View\View;
use PHPUnit\Framework\TestCase;

final class ConcurrencyTest extends TestCase
{
    public function testATickThatLostTheRaceIsReplayedOnTopOfTheWinner(): void
    {
        $storage = new MemoryStorage();
        $observer = new TraceRuntimeObserver();
        $adapter = new FakePlatformAdapter();

        $application = TestApp::create($adapter, $storage, $observer);
        $racer = new RacingMiddleware(self::otherWorker($storage), once: true);

        $application->middleware([$racer]);
        $application->onCommand('mine', static function (Context $ctx): void {
            $ctx->session()->increment('writes');
            $ctx->session()->set('last', 'mine');
            $ctx->reply(View::text('done'));
        });

        $result = $application->handle(TestApp::event('conv-1', '/mine'));

        self::assertTrue($result->isSuccess());
        self::assertSame(2, $racer->attempts, 'The tick was replayed once.');
        self::assertContains('conversation.conflict', $observer->getNames());

        $session = $application->getConversations()->resume('conv-1')->getContext();

        self::assertSame(2, $session->getInt('writes'), 'Neither worker lost its change.');
        self::assertSame('mine', $session->getString('last'));
        self::assertCount(1, $adapter->replies, 'The discarded attempt delivered nothing.');
    }

    public function testAConversationUnderConstantChangeGivesUpInsteadOfLooping(): void
    {
        $storage = new MemoryStorage();
        $adapter = new FakePlatformAdapter();

        $application = TestApp::create($adapter, $storage);
        $racer = new RacingMiddleware(self::otherWorker($storage), once: false);

        $application->middleware([$racer]);
        $application->onCommand('mine', static function (Context $ctx): void {
            $ctx->session()->increment('writes');
            $ctx->reply(View::text('done'));
        });

        $result = $application->handle(TestApp::event('conv-1', '/mine'));

        self::assertTrue($result->isError());
        self::assertSame('conversation_conflict', $result->getMessage());
        self::assertSame(3, $racer->attempts);
        self::assertCount(0, $adapter->replies, 'A conversation that never settles answers nothing.');
    }

    public function testDriversWithoutCompareAndSwapStillDetectTheConflict(): void
    {
        $storage = new class implements StorageInterface {
            private readonly MemoryStorage $inner;

            public function __construct()
            {
                $this->inner = new MemoryStorage();
            }

            public function get(string $key): ?array
            {
                return $this->inner->get($key);
            }

            public function save(string $key, array $data): void
            {
                $this->inner->save($key, $data);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }

            public function exists(string $key): bool
            {
                return $this->inner->exists($key);
            }
        };

        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter, $storage);
        $racer = new RacingMiddleware(self::otherWorker($storage), once: true);

        $application->middleware([$racer]);
        $application->onCommand('mine', static function (Context $ctx): void {
            $ctx->session()->increment('writes');
            $ctx->reply(View::text('done'));
        });

        $result = $application->handle(TestApp::event('conv-1', '/mine'));

        self::assertTrue($result->isSuccess());
        self::assertSame(2, $racer->attempts);
        self::assertSame(2, $application->getConversations()->resume('conv-1')->getContext()->getInt('writes'));
    }

    /**
     * A second application on the same storage: another webhook worker handling the same chat.
     */
    private static function otherWorker(StorageInterface $storage): Application
    {
        $other = TestApp::create(new FakePlatformAdapter(), $storage);
        $other->onCommand('other', static function (Context $ctx): void {
            $ctx->session()->increment('writes');
            $ctx->session()->set('last', 'other');
        });

        return $other;
    }
}
