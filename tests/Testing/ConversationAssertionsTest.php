<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Testing;

use Automata\Clock\FrozenClock;
use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\Core\Result;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundEvent;
use ChatFlow\SideEffect\SideEffect;
use ChatFlow\Testing\ConversationAssertions;
use ChatFlow\Testing\FakePlatformAdapter;
use ChatFlow\Tests\Support\HookLog;
use ChatFlow\Tests\Support\Scenes\ReminderScene;
use ChatFlow\Tests\Support\TestApp;
use ChatFlow\Timer\Drivers\MemoryTimerStore;
use DateTimeImmutable;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class ConversationAssertionsTest extends TestCase
{
    public function testDirectAssertionsOnApplicationState(): void
    {
        $clock = FrozenClock::at('2026-09-12 10:00:00+00:00');
        $timers = new MemoryTimerStore();
        $container = new Container();
        $container->set(HookLog::class, new HookLog());
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter, container: $container, clock: $clock, timers: $timers);
        $application->registerScene(ReminderScene::class);
        $application->registerSideEffect('crm', static fn(SideEffect $effect): array => ['ok' => $effect->payload['n']]);
        $application->onSideEffect('crm', static function (Context $ctx, SideEffect $effect, array $result): void {
            $ctx->session()->set('crm', $result['ok']);
        });

        $assertions = new ConversationAssertions($application, 'conv-test');

        self::assertSame('conv-test', $assertions->conversation()->getId());
        self::assertInstanceOf(ConversationRef::class, $assertions->conversation());
        self::assertSame('conv-test', $assertions->resume()->getId());

        $assertions->assertNotInScene()
            ->assertNoScenePending()
            ->assertNoSideEffectsPending()
            ->assertSessionMissing('crm')
            ->assertNoTimer('silence');

        self::assertNull($assertions->getLastResult());

        // Dispatch an event
        $result = $application->handle(new InboundEvent(new ConversationRef('conv-test'), text: 'enter'));
        $assertions->recordResult($result);

        self::assertSame($result, $assertions->getLastResult());
        $assertions->assertResult('no_match');

        // Test with scene and pending transition via ConversationManager
        $application->getConversations()->enterLater('conv-pending', 'reminder');
        $pendingAssertions = new ConversationAssertions($application, 'conv-pending');
        $pendingAssertions->assertScenePending('reminder')
            ->assertScenePending(ReminderScene::class);

        // Resume and enter scene directly via command on conv-test
        $application->onCommand('go', static function (Context $ctx): void {
            $ctx->enter('reminder');
            $ctx->session()->set('step', 1);
            $ctx->session()->set('nullable', null);
            $ctx->schedule('crm', ['n' => 42]);
            $ctx->wakeAt(new DateTimeImmutable('2026-09-12 11:00:00+00:00'), 'alarm');
        });

        $result3 = $application->handle(new InboundEvent(new ConversationRef('conv-test'), text: '/go'));
        $assertions->recordResult($result3);

        $assertions->assertResult('success', 'route_processed')
            ->assertNoScenePending()
            ->assertScene('reminder')
            ->assertScene(ReminderScene::class)
            ->assertSessionHas('step')
            ->assertSessionHas('step', 1)
            ->assertSessionHas('nullable')
            ->assertSessionMissing('unknown')
            ->assertNoSideEffectsPending()
            ->assertSessionHas('crm', 42)
            ->assertTimerScheduled('alarm')
            ->assertTimerScheduled('alarm', new DateTimeImmutable('2026-09-12 11:00:00+00:00'))
            ->assertNoTimer('other');
    }

    public function testFailedAssertionsThrowExpectedErrors(): void
    {
        $application = TestApp::create(new FakePlatformAdapter());
        $assertions = new ConversationAssertions($application, 'conv-err');

        // assertResult before any dispatch
        try {
            $assertions->assertResult('success');
            self::fail('assertResult should have failed');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('No event was dispatched yet.', $e->getMessage());
        }

        $assertions->recordResult(new Result('success', 'done'));
        $assertions->assertResult('success');

        try {
            $assertions->assertResult('failed');
            self::fail('assertResult should have failed');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Failed asserting that two strings are identical.', $e->getMessage());
        }

        try {
            $assertions->assertResult('success', 'other_message');
            self::fail('assertResult should have failed');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Failed asserting that two strings are identical.', $e->getMessage());
        }

        // assertScene when not in scene
        try {
            $assertions->assertScene('unknown');
            self::fail('assertScene should have failed');
        } catch (AssertionFailedError|\ChatFlow\Exception\SceneNotFoundException $e) {
            self::assertNotEmpty($e->getMessage());
        }

        // assertSessionHas
        try {
            $assertions->assertSessionHas('missing_key');
            self::fail('assertSessionHas should have failed');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('Session key "missing_key" is missing.', $e->getMessage());
        }

        // assertScenePending when none pending
        try {
            $assertions->assertScenePending('reminder');
            self::fail('assertScenePending should have failed');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('No scene transition is pending.', $e->getMessage());
        }

        // assertSideEffectPending when none pending
        try {
            $assertions->assertSideEffectPending('missing_handler');
            self::fail('assertSideEffectPending should have failed');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('No pending side effect for "missing_handler".', $e->getMessage());
        }

        // assertTimerScheduled when no timer store or none scheduled
        try {
            $assertions->assertTimerScheduled('alarm');
            self::fail('assertTimerScheduled should have failed');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('No timer with reason "alarm" is scheduled.', $e->getMessage());
        }
    }

    public function testSideEffectFailureAndPendingAssertions(): void
    {
        $application = TestApp::create(new FakePlatformAdapter());
        $application->onCommand('fail_effect', static function (Context $ctx): void {
            $ctx->schedule('unhandled_effect');
        });

        $assertions = new ConversationAssertions($application, 'conv-fx');
        $result = $application->handle(new InboundEvent(new ConversationRef('conv-fx'), text: '/fail_effect'));
        $assertions->recordResult($result);

        $assertions->assertSideEffectFailed('unhandled_effect')
            ->assertNoSideEffectsPending();

        try {
            $assertions->assertSideEffectFailed('other_effect');
            self::fail('assertSideEffectFailed should have failed');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('No failed side effect for "other_effect".', $e->getMessage());
        }
    }
}
