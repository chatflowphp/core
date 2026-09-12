<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Core;

use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\SideEffect\SideEffect;
use ChatFlow\SideEffect\SideEffectHandlerInterface;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Tests\Support\FakePlatformAdapter;
use ChatFlow\Tests\Support\HookLog;
use ChatFlow\Tests\Support\Scenes\OrderScene;
use ChatFlow\Tests\Support\TestApp;
use ChatFlow\Tests\Support\TraceRuntimeObserver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SideEffectTest extends TestCase
{
    public function testScheduledEffectRunsAfterTheReplyIsDeliveredAndIsRemoved(): void
    {
        $adapter = new FakePlatformAdapter();
        $observer = new TraceRuntimeObserver();
        $application = TestApp::create($adapter, observer: $observer);
        $seen = [];

        $application->registerSideEffect('notify', static function (SideEffect $effect) use (&$seen, $adapter): ?array {
            $seen[] = [$effect->id, $effect->payload, \count($adapter->replies)];

            return null;
        });
        $application->onTextPrefix('/go', static function (Context $ctx): void {
            $ctx->schedule('notify', ['to' => 'ops', 'n' => 1]);
            $ctx->reply('queued');
        });

        $result = $application->handle(TestApp::event('conv-1', '/go'));

        self::assertTrue($result->isSuccess());
        self::assertSame([['conv-1:1:1', ['to' => 'ops', 'n' => 1], 1]], $seen, 'The handler ran once, after the reply was delivered.');
        self::assertSame([], $application->getConversations()->resume('conv-1')->getContext()->getSideEffects());
        self::assertContains('side_effect.scheduled', $observer->getNames());
        self::assertContains('side_effect.executed', $observer->getNames());
    }

    public function testEffectsVanishWithARolledBackTick(): void
    {
        $application = TestApp::create();
        $ran = 0;

        $application->registerSideEffect('notify', static function () use (&$ran): ?array {
            $ran++;

            return null;
        });
        $application->onTextPrefix('/boom', static function (Context $ctx): void {
            $ctx->schedule('notify');

            throw new RuntimeException('tick failed');
        });

        $result = $application->handle(TestApp::event('conv-1', '/boom'));

        self::assertTrue($result->isError());
        self::assertSame(0, $ran);
        self::assertSame([], $application->getConversations()->resume('conv-1')->getContext()->getSideEffects());
    }

    public function testFailedEffectStaysPendingAndIsRetriedOnTheNextEventOrDrain(): void
    {
        $storage = new MemoryStorage();
        $observer = new TraceRuntimeObserver();
        $application = TestApp::create(storage: $storage, observer: $observer);
        $attempts = 0;

        $application->registerSideEffect('flaky', static function () use (&$attempts): ?array {
            $attempts++;

            if ($attempts < 3) {
                throw new RuntimeException('upstream down');
            }

            return null;
        });
        $application->onTextPrefix('/go', static function (Context $ctx): void {
            $ctx->schedule('flaky');
        });
        $application->onTextPrefix('/ping', static function (Context $ctx): void {
            $ctx->reply('pong');
        });

        $application->handle(TestApp::event('conv-1', '/go'));
        $pending = $application->getConversations()->resume('conv-1')->getContext()->getSideEffects();
        self::assertCount(1, $pending);
        self::assertSame(1, $pending[0]->attempts);
        self::assertSame('upstream down', $pending[0]->error);

        $application->handle(TestApp::event('conv-1', '/ping'));
        self::assertSame(2, $attempts, 'The next event retried the effect.');

        self::assertSame(1, $application->drain('conv-1'));
        self::assertSame(3, $attempts);
        self::assertSame([], $application->getConversations()->resume('conv-1')->getContext()->getSideEffects());
        self::assertSame(0, $application->drain('conv-1'));
        self::assertContains('side_effect.failed', $observer->getNames());
    }

    public function testEffectIsAbandonedAfterTooManyFailuresOrWhenTheHandlerIsUnknown(): void
    {
        $observer = new TraceRuntimeObserver();
        $application = TestApp::create(observer: $observer);
        $application->registerSideEffect('broken', static function (): ?array {
            throw new RuntimeException('always');
        });
        $application->onTextPrefix('/go', static function (Context $ctx): void {
            $ctx->schedule('broken', id: 'b');
            $ctx->schedule('nobody', id: 'n');
        });

        $application->handle(TestApp::event('conv-1', '/go'));

        for ($i = 0; $i < 4; $i++) {
            $application->drain('conv-1');
        }

        $session = $application->getConversations()->resume('conv-1')->getContext();
        self::assertSame([], $session->getSideEffects());
        $failed = $session->getFailedSideEffects();
        self::assertSame(['n', 'b'], array_map(static fn(SideEffect $effect): string => $effect->id, $failed));
        self::assertSame(1, $failed[0]->attempts);
        self::assertStringContainsString('No side effect handler', (string) $failed[0]->error);
        self::assertSame(5, $failed[1]->attempts);
        self::assertSame(2, \count(array_keys($observer->getNames(), 'side_effect.abandoned', true)));

        $session->clearFailedSideEffects();
        self::assertSame([], $session->getFailedSideEffects());
    }

    public function testResultReachesTheSceneInsideASystemTickAndFollowUpEffectsRunToo(): void
    {
        $container = new Container();
        $log = new HookLog();
        $container->set(HookLog::class, $log);
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter, container: $container);
        $application->registerScene(OrderScene::class);
        $application->registerSideEffect('charge', new class implements SideEffectHandlerInterface {
            public function handle(SideEffect $effect): array
            {
                $amount = \is_int($effect->payload['amount'] ?? null) ? $effect->payload['amount'] : 0;

                return ['receipt' => 'R-' . $amount, 'final' => $amount > 100];
            }
        });
        $application->enter('conv-1', 'order');

        $application->handle(TestApp::event('conv-1', '50'));

        $conversation = $application->getConversations()->resume('conv-1');
        self::assertSame('order', $conversation->getCurrentScene());
        self::assertSame('R-50', $conversation->getContext()->get('receipt'));
        self::assertSame(['Order:scheduled:conv-1:2:1', 'Order:result:charge:{"receipt":"R-50","final":false}'], $log->all());
        self::assertSame(['Amount?', 'Charging 50', 'Charged, receipt R-50'], array_map(static fn($view): string => $view->getText(), $adapter->replies));

        $application->handle(TestApp::event('conv-1', '500'));

        self::assertSame('root', $application->getConversations()->resume('conv-1')->getCurrentScene(), 'The listener left the scene.');
    }

    public function testResultFallsBackToTheApplicationListenerOutsideScenes(): void
    {
        $adapter = new FakePlatformAdapter();
        $observer = new TraceRuntimeObserver();
        $application = TestApp::create($adapter, observer: $observer);
        $application->registerSideEffect('lookup', static fn(SideEffect $effect): array => ['found' => $effect->payload['id']]);
        $application->onSideEffect('lookup', static function (Context $ctx, SideEffect $effect, array $result): void {
            $ctx->reply('Result for ' . $effect->id . ': ' . json_encode($result, JSON_THROW_ON_ERROR));
        });
        $application->registerSideEffect('silent', static fn(): array => ['ignored' => true]);
        $application->onTextPrefix('/go', static function (Context $ctx): void {
            $ctx->schedule('lookup', ['id' => 7], 'l1');
            $ctx->schedule('silent', id: 's1');
        });

        $application->handle(TestApp::event('conv-1', '/go'));

        self::assertSame(['Result for l1: {"found":7}'], array_map(static fn($view): string => $view->getText(), $adapter->replies));
        self::assertContains('side_effect.result_dropped', $observer->getNames());
    }

    public function testSchedulingAPendingIdAgainChangesNothing(): void
    {
        $observer = new TraceRuntimeObserver();
        $application = TestApp::create(observer: $observer);
        $application->onTextPrefix('/go', static function (Context $ctx): void {
            $first = $ctx->schedule('x', ['n' => 1], 'same');
            $second = $ctx->schedule('x', ['n' => 2], 'same');
            $ctx->set('ids', [$first->id, $second->id, $second->payload['n']]);
        });

        $application->handle(TestApp::event('conv-1', '/go'));

        $pending = $application->getConversations()->resume('conv-1')->getContext()->getSideEffects();
        self::assertSame([], $pending, 'An unknown handler is abandoned, not kept pending.');
        $failed = $application->getConversations()->resume('conv-1')->getContext()->getFailedSideEffects();
        self::assertCount(1, $failed);
        self::assertSame(['n' => 1], $failed[0]->payload);
        self::assertContains('side_effect.duplicate', $observer->getNames());
    }

    public function testSideEffectsRoundTripThroughTheSnapshot(): void
    {
        $effect = new SideEffect('id', 'handler', ['a' => 1, 'b' => ['c' => 'д']]);
        $failed = $effect->withFailedAttempt('boom');

        self::assertSame(1, $failed->attempts);
        self::assertSame('boom', $failed->error);
        self::assertEquals($failed, SideEffect::fromArray($failed->toArray()));
        self::assertNull(SideEffect::fromArray(['id' => '', 'handler' => 'x']));
        self::assertNull(SideEffect::fromArray(['id' => 'x', 'handler' => 'y', 'payload' => 'not-array']));
    }
}
