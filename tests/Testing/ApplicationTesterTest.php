<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Testing;

use Automata\Clock\FrozenClock;
use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Exception\LogicException;
use ChatFlow\Platform\ListeningPlatformAdapter;
use ChatFlow\SideEffect\SideEffect;
use ChatFlow\Testing\ApplicationTester;
use ChatFlow\Testing\FakePlatformAdapter;
use ChatFlow\Tests\Support\HookLog;
use ChatFlow\Tests\Support\Scenes\ReminderScene;
use ChatFlow\Tests\Support\TestApp;
use ChatFlow\Timer\Drivers\MemoryTimerStore;
use ChatFlow\View\Action;
use ChatFlow\View\View;
use DateTimeImmutable;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class ApplicationTesterTest extends TestCase
{
    public function testDrivesRoutesScenesSideEffectsAndTimers(): void
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
        $application->onCommand('start', static function (Context $ctx): void {
            $ctx->reply(View::text('Welcome, ' . (string) $ctx->getUserId())->addActionRow(new Action('remind', 'Remind me')));
        });
        $application->onAction('remind', static function (Context $ctx): void {
            $ctx->schedule('crm', ['n' => 7]);
            $ctx->enter('reminder');
        });

        $tester = new ApplicationTester($application, $adapter, clock: $clock);

        $tester->as('chat-9', 42)->send('/start')
            ->assertResult('success', 'route_processed')
            ->assertReplied('Welcome, 42')
            ->assertSee('Welcome')
            ->assertDontSee('Bye')
            ->assertActionOffered('remind')
            ->assertNotInScene();

        $tester->press('remind')
            ->assertScene('reminder')
            ->assertScene(ReminderScene::class)
            ->assertSee('Talk to me')
            ->assertNoSideEffectsPending()
            ->assertSessionHas('crm', 7)
            ->assertSessionMissing('nudges');

        $tester->clear()->at(new DateTimeImmutable('2026-09-12 09:59:00+00:00'))->send('hi')
            ->assertReplied('Noted at 09:59')
            ->assertTimerScheduled('silence', new DateTimeImmutable('2026-09-12 11:00:00+00:00'))
            ->assertNoTimer('digest');

        self::assertSame(0, $tester->travel(1800));
        self::assertSame(1, $tester->travel(new DateTimeImmutable('2026-09-12 11:00:00+00:00')));

        $tester->assertReplied('Still there?')
            ->assertNotInScene()
            ->assertSessionHas('nudges', 1)
            ->assertNoTimer('silence');

        self::assertSame(['Noted at 09:59', 'Still there?'], $tester->getTexts());
        self::assertSame('Still there?', $tester->getLastReply()?->getText());
        self::assertCount(2, $tester->getReplies());
        self::assertNotNull($tester->getLastResult());
        self::assertSame('chat-9', $tester->resume()->getId());

        $tester->clear()->system('cron')->assertNoReply()->assertResult('no_match');
        $tester->attach(new InboundAttachment('photo', 'file-1'), 'look')->assertResult('no_match');
        self::assertSame(0, $tester->drain());
    }

    public function testFailedAssertionsExplainThemselves(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter);
        $application->onCommand('start', static function (Context $ctx): void {
            $ctx->reply('Hello');
        });
        $tester = new ApplicationTester($application, $adapter);
        $tester->send('/start');

        try {
            $tester->assertSee('Goodbye');
            self::fail('assertSee should have failed.');
        } catch (AssertionFailedError $exception) {
            self::assertStringContainsString('Goodbye', $exception->getMessage());
            self::assertStringContainsString('Hello', $exception->getMessage());
        }

        try {
            $tester->assertActionOffered('nope');
            self::fail('assertActionOffered should have failed.');
        } catch (AssertionFailedError $exception) {
            self::assertStringContainsString('nope', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $tester->travel(10);
    }

    public function testSideEffectFailureIsVisible(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter);
        $application->onCommand('go', static function (Context $ctx): void {
            $ctx->schedule('missing');
        });

        (new ApplicationTester($application, $adapter))->send('/go')->assertSideEffectFailed('missing')->assertNoSideEffectsPending();
    }

    public function testListeningAdapterHearsButNeverAnswers(): void
    {
        $inner = new FakePlatformAdapter();
        $heard = [];
        $listening = new ListeningPlatformAdapter($inner, static function (Context $ctx, $effect) use (&$heard): void {
            $heard[] = $ctx->getConversationId() . ':' . $effect->getType();
        });
        $application = TestApp::create($listening);
        $application->onCommand('start', static function (Context $ctx): void {
            $ctx->reply('Hello');
            $ctx->ack('ok');
        });

        $result = $application->handle(TestApp::event('conv-1', '/start'));

        self::assertTrue($result->isSuccess());
        self::assertSame([], $inner->replies, 'Nothing reached the real adapter.');
        self::assertSame([], $inner->afterHandle, 'The real adapter hook stayed silent.');
        self::assertSame(['conv-1:reply', 'conv-1:ack'], $heard);
        self::assertCount(2, $listening->getEffects());
        self::assertTrue($listening->capabilities()->supportsAck());
        $listening->clear();
        self::assertSame([], $listening->getEffects());
    }
}
