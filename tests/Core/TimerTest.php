<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Core;

use Automata\Clock\FrozenClock;
use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundEvent;
use ChatFlow\Exception\LogicException;
use ChatFlow\Testing\FakePlatformAdapter;
use ChatFlow\Tests\Support\HookLog;
use ChatFlow\Tests\Support\Scenes\ReminderScene;
use ChatFlow\Tests\Support\TestApp;
use ChatFlow\Tests\Support\TraceRuntimeObserver;
use ChatFlow\Timer\Drivers\MemoryTimerStore;
use ChatFlow\Timer\Timer;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TimerTest extends TestCase
{
    public function testASceneArmsATimerAndIsWokenWhenItIsDue(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-12 10:00:00+00:00'));
        $timers = new MemoryTimerStore();
        $container = new Container();
        $log = new HookLog();
        $container->set(HookLog::class, $log);
        $adapter = new FakePlatformAdapter();
        $observer = new TraceRuntimeObserver();
        $application = TestApp::create($adapter, observer: $observer, container: $container, clock: $clock, timers: $timers);
        $application->registerScene(ReminderScene::class);
        $application->enter('conv-1', 'reminder');

        $application->handle(new InboundEvent(new ConversationRef('conv-1'), text: 'hello', occurredAt: new DateTimeImmutable('2026-09-12 09:58:00+00:00')));

        $scheduled = $timers->forConversation('conv-1');
        self::assertCount(1, $scheduled);
        self::assertSame('conv-1:silence', $scheduled[0]->id);
        self::assertSame('2026-09-12T11:00:00+00:00', $scheduled[0]->toArray()['at'], 'Counted from the clock, not from the event.');
        self::assertSame('Noted at 09:58', $adapter->replies[1]->getText());

        $application->handle(TestApp::event('conv-1', 'again'));
        self::assertCount(1, $timers->forConversation('conv-1'), 'The same reason moves the timer.');
        self::assertSame(['last' => 'again'], $timers->forConversation('conv-1')[0]->payload);

        self::assertSame(0, $application->runDue());
        self::assertSame(0, $application->runDue(new DateTimeImmutable('2026-09-12 10:59:59+00:00')));

        self::assertSame(1, $application->runDue(new DateTimeImmutable('2026-09-12 11:00:00+00:00')));

        self::assertSame(['Reminder:timer:silence:{"last":"again"}'], $log->all());
        self::assertSame('Still there?', $adapter->replies[3]->getText());
        $conversation = $application->getConversations()->resume('conv-1');
        self::assertSame('root', $conversation->getCurrentScene(), 'The listener left the scene inside the timer tick.');
        self::assertSame(1, $conversation->getContext()->get('nudges'));
        self::assertSame([], $timers->forConversation('conv-1'), 'A timer that ran is cancelled.');
        self::assertContains('timer.ran', $observer->getNames());
    }

    public function testCancelTimerRemovesItAndARolledBackTickSchedulesNothing(): void
    {
        $timers = new MemoryTimerStore();
        $container = new Container();
        $container->set(HookLog::class, new HookLog());
        $application = TestApp::create(container: $container, timers: $timers);
        $application->registerScene(ReminderScene::class);
        $application->enter('conv-1', 'reminder');

        $application->handle(TestApp::event('conv-1', 'hi'));
        self::assertCount(1, $timers->forConversation('conv-1'));

        $application->handle(TestApp::event('conv-1', 'stop'));
        self::assertSame([], $timers->forConversation('conv-1'));

        $application->onTextPrefix('/boom', static function (Context $ctx): void {
            $ctx->wakeAt(new DateInterval('P1D'), 'never');

            throw new RuntimeException('tick failed');
        });
        $application->leave('conv-1');
        $application->handle(TestApp::event('conv-1', '/boom'));

        self::assertSame([], $timers->forConversation('conv-1'));
    }

    public function testTheApplicationListenerReceivesTimersOutsideScenesAndUnhandledOnesAreDropped(): void
    {
        $timers = new MemoryTimerStore();
        $adapter = new FakePlatformAdapter();
        $observer = new TraceRuntimeObserver();
        $application = TestApp::create($adapter, observer: $observer, timers: $timers);
        $application->onTimer('digest', static function (Context $ctx, Timer $timer): void {
            $ctx->reply('Digest for ' . $ctx->getConversationId() . ': ' . json_encode($timer->payload, JSON_THROW_ON_ERROR));
        });
        $application->onTextPrefix('/subscribe', static function (Context $ctx): void {
            $ctx->wakeAt(new DateTimeImmutable('2026-09-13 08:00:00+00:00'), 'digest', ['topics' => 2]);
            $ctx->wakeAt(new DateTimeImmutable('2026-09-13 08:00:00+00:00'), 'orphan');
        });

        $application->handle(TestApp::event('conv-1', '/subscribe'));
        $application->handle(TestApp::event('conv-2', '/subscribe'));

        self::assertSame(4, $application->runDue(new DateTimeImmutable('2026-09-13 08:00:00+00:00')));
        self::assertSame(
            ['Digest for conv-1: {"topics":2}', 'Digest for conv-2: {"topics":2}'],
            array_map(static fn($view): string => $view->getText(), $adapter->replies),
        );
        self::assertSame(2, \count(array_keys($observer->getNames(), 'timer.dropped', true)));
        self::assertSame(0, $application->runDue(new DateTimeImmutable('2026-09-13 08:00:00+00:00')));
    }

    public function testTimersNeedAStore(): void
    {
        $application = TestApp::create();
        $application->onTextPrefix('/x', static function (Context $ctx): void {
            $ctx->wakeAt(10, 'x');
        });

        $result = $application->handle(TestApp::event('conv-1', '/x'));

        self::assertTrue($result->isError());
        self::assertSame(LogicException::class, $result->getData()['exception'] ?? null);
        self::assertSame(0, $application->runDue());
        self::assertNull($application->getTimers());
    }
}
