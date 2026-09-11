<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Scene;

use Automata\Snapshot\StateSnapshot;
use ChatFlow\Container\Container;
use ChatFlow\Core\Application;
use ChatFlow\Core\Context;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Scene\RootScene;
use ChatFlow\Scene\SceneContext;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Tests\Support\FakePlatformAdapter;
use ChatFlow\Tests\Support\HookLog;
use ChatFlow\Tests\Support\MutableClock;
use ChatFlow\Tests\Support\Scenes\BrokenScene;
use ChatFlow\Tests\Support\Scenes\CheckoutScene;
use ChatFlow\Tests\Support\Scenes\CustomMiddlewareScene;
use ChatFlow\Tests\Support\Scenes\DetailsScene;
use ChatFlow\Tests\Support\Scenes\MenuScene;
use ChatFlow\Tests\Support\Scenes\StatefulScene;
use ChatFlow\Tests\Support\Scenes\SurveyScene;
use ChatFlow\Tests\Support\TestApp;
use ChatFlow\Tests\Support\TraceRuntimeObserver;
use ChatFlow\View\View;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SceneFlowTest extends TestCase
{
    private const INTERNAL_ERROR = 'An internal error occurred. Please try again later.';

    private FakePlatformAdapter $adapter;

    private MemoryStorage $storage;

    private HookLog $log;

    private TraceRuntimeObserver $observer;

    private MutableClock $clock;

    private Application $app;

    protected function setUp(): void
    {
        $this->adapter = new FakePlatformAdapter();
        $this->storage = new MemoryStorage();
        $this->log = new HookLog();
        $this->observer = new TraceRuntimeObserver();
        $this->clock = new MutableClock();
        $this->app = $this->createApp();
    }

    public function testSurveyLifecyclePersistsAcrossRequests(): void
    {
        $this->app->registerScene(SurveyScene::class);
        $this->app->onCommand('survey', static function (Context $ctx): void {
            $ctx->enter(SurveyScene::class);
        });

        self::assertTrue($this->app->handle(TestApp::event('c', '/survey'))->isSuccess());
        self::assertSame('Как вас зовут?', $this->lastReply());
        self::assertSame(SurveyScene::class, $this->currentScene('c'));

        $this->app->handle(TestApp::event('c', 'Alex'));
        self::assertSame('Сколько вам лет?', $this->lastReply());
        self::assertSame('Alex', $this->session('c')->get('name'));

        $this->app->handle(TestApp::event('c', 'oops'));
        self::assertSame('Возраст должен быть числом', $this->lastReply());
        self::assertSame(SurveyScene::class, $this->currentScene('c'));

        $this->app->handle(TestApp::event('c', '30'));
        self::assertSame('Данные сохранены', $this->lastReply());
        self::assertSame(30, $this->session('c')->get('age'));

        $result = $this->app->handle(TestApp::event('c', actionId: 'scene:onOk'));
        self::assertSame('scene_processed', $result->getMessage());
        self::assertSame('Всего доброго!', $this->lastReply());
        self::assertSame(RootScene::ID, $this->currentScene('c'));
        self::assertSame([['text' => null, 'error' => false]], $this->adapter->acks);
        self::assertSame('Alex', $this->session('c')->get('name'), 'session data survives leaving the scene');
    }

    public function testGlobalCommandsInterruptTheSceneWhichStaysActive(): void
    {
        $this->registerMenuFlow();
        $this->app->onCommand('help', static function (Context $ctx): void {
            $ctx->reply('HELP');
        });

        $this->app->handle(TestApp::event('c', '/start'));
        $result = $this->app->handle(TestApp::event('c', '/help'));

        self::assertSame('global_route_processed', $result->getMessage());
        self::assertSame('HELP', $this->lastReply());
        self::assertSame(MenuScene::class, $this->currentScene('c'));
        self::assertNotContains('Menu:handle:/help', $this->log->all());
    }

    public function testASceneCanRefuseGlobalRoutes(): void
    {
        $this->registerMenuFlow();
        $this->app->onCommand('help', static function (Context $ctx): void {
            $ctx->reply('HELP');
        });

        $this->app->handle(TestApp::event('c', '/checkout'));
        $this->app->handle(TestApp::event('c', '/help'));

        self::assertSame('Digits only', $this->lastReply());
        self::assertSame('checkout', $this->currentScene('c'));
    }

    public function testNonGlobalRoutesDoNotReachAnActiveScene(): void
    {
        $this->registerMenuFlow();
        $this->app->onTextPrefix('echo', static function (Context $ctx): void {
            $ctx->reply('echoed');
        });

        $this->app->handle(TestApp::event('c', '/start'));
        $this->app->handle(TestApp::event('c', 'echo hi'));

        self::assertContains('Menu:handle:echo hi', $this->log->all());
        self::assertSame('Menu got echo hi', $this->lastReply());
    }

    public function testEnterBackAndLeaveMaintainHistoryAndRunHooksInOrder(): void
    {
        $this->registerMenuFlow();

        $this->app->handle(TestApp::event('c', '/start'));
        self::assertSame(['Menu:enter'], $this->log->all());

        $this->log->clear();
        $this->app->handle(TestApp::event('c', actionId: 'scene:onGoDetails'));
        self::assertSame(['Menu:leave', 'Details:enter'], $this->log->all());
        self::assertSame(DetailsScene::class, $this->currentScene('c'));
        self::assertSame(42, $this->session('c')->get('item'));
        self::assertSame([['scene' => MenuScene::class, 'title' => 'Menu']], $this->session('c')->getHistory());
        self::assertSame('Details screen', $this->lastReply());

        $this->log->clear();
        $this->app->handle(TestApp::event('c', actionId: 'scene:onBack'));
        self::assertSame(['Details:leave', 'Menu:enter'], $this->log->all());
        self::assertSame(MenuScene::class, $this->currentScene('c'));
        self::assertSame([], $this->session('c')->getHistory());

        $this->log->clear();
        $this->app->handle(TestApp::event('c', actionId: 'scene:onGoDetails'));
        $this->app->handle(TestApp::event('c', '/close'));
        self::assertSame(RootScene::ID, $this->currentScene('c'));
        self::assertSame([], $this->session('c')->getHistory());
        self::assertSame(['Menu:leave', 'Details:enter', 'Details:leave'], $this->log->all());
    }

    public function testBackFromTheFirstSceneReturnsToRoot(): void
    {
        $this->registerMenuFlow();

        $this->app->handle(TestApp::event('c', '/details'));
        $this->app->handle(TestApp::event('c', actionId: 'scene:onBack'));

        self::assertSame(RootScene::ID, $this->currentScene('c'));
        self::assertContains('scene.left', $this->observer->getNames());
    }

    public function testReenteringTheActiveSceneRunsOnEnterAgainWithoutHistory(): void
    {
        $this->registerMenuFlow();

        $this->app->handle(TestApp::event('c', '/start'));
        $this->log->clear();
        $this->app->handle(TestApp::event('c', actionId: 'scene:onRefresh'));

        self::assertSame(['Menu:enter'], $this->log->all());
        self::assertSame([], $this->session('c')->getHistory());
        self::assertSame(MenuScene::class, $this->currentScene('c'));
    }

    public function testSceneActionsReceivePayloadAsNamedParametersAndAsParams(): void
    {
        $this->registerMenuFlow();

        $this->app->handle(TestApp::event('c', '/start'));
        $this->app->handle(TestApp::event('c', actionId: 'scene:onOpen', payload: ['section' => 'settings', 'page' => 2]));

        self::assertContains('Menu:onOpen:settings:{"section":"settings","page":2}', $this->log->all());
        self::assertSame('Section settings', $this->lastReply());
    }

    public function testProtectedMethodsAndUnknownActionsFallBackToHandle(): void
    {
        $this->registerMenuFlow();

        $this->app->handle(TestApp::event('c', '/start'));
        $this->log->clear();
        $this->app->handle(TestApp::event('c', actionId: 'scene:helper'));
        $this->app->handle(TestApp::event('c', actionId: 'scene:onMissing'));

        self::assertSame(['Menu:handle:', 'Menu:handle:'], $this->log->all());
    }

    public function testInteractionShortcutsValidationAndMediaFallbacks(): void
    {
        $this->registerMenuFlow();

        $this->app->handle(TestApp::event('c', '/start'));
        $this->app->handle(TestApp::event('c', actionId: 'scene:onGoCheckout'));
        self::assertSame('Phone?', $this->lastReply());

        $this->app->handle(TestApp::event('c', 'abc'));
        self::assertSame('Digits only', $this->lastReply());
        self::assertSame('checkout', $this->currentScene('c'));

        $this->app->handle(TestApp::event('c', 'STOP now'));
        self::assertContains('Checkout:cancel', $this->log->all());
        self::assertSame(MenuScene::class, $this->currentScene('c'), 'cancel goes back to the previous scene');

        $this->app->handle(TestApp::event('c', actionId: 'scene:onGoCheckout'));
        $this->app->handle(TestApp::event('c', '+123'));
        self::assertSame('+123', $this->session('c')->get('phone'));
        self::assertSame(RootScene::ID, $this->currentScene('c'));

        $this->app->handle(TestApp::event('c', '/checkout'));
        $this->app->handle(TestApp::event('c', attachments: [new InboundAttachment('photo', 'file-1')]));
        self::assertSame('Got a photo', $this->lastReply());
        self::assertFalse($this->session('c')->hasInteraction(), 'fallback handlers consume the interaction');
    }

    public function testAFailureRollsBackSceneSessionAndEffects(): void
    {
        $this->registerMenuFlow();

        $this->app->handle(TestApp::event('c', '/start'));
        $this->adapter->replies = [];
        $this->log->clear();

        $result = $this->app->handle(TestApp::event('c', actionId: 'scene:onSelfDestruct'));

        self::assertTrue($result->isError());
        self::assertSame(['Menu:leave', 'Details:enter'], $this->log->all(), 'the transition ran before the failure');
        self::assertSame([], $this->replies(), 'nothing queued before the failure is sent');
        self::assertSame([['text' => self::INTERNAL_ERROR, 'error' => true]], $this->adapter->acks, 'a failed button press is acknowledged as an alert');
        self::assertSame(MenuScene::class, $this->currentScene('c'));
        self::assertFalse($this->session('c')->has('poisoned'));
        self::assertSame([], $this->session('c')->getHistory());
    }

    public function testAFailureInsideHandleRestoresSessionData(): void
    {
        $this->app->registerScene(StatefulScene::class);
        $this->app->onCommand('stateful', static function (Context $ctx): void {
            $ctx->enter(StatefulScene::class);
        });

        $this->app->handle(TestApp::event('c', '/stateful'));
        $this->app->handle(TestApp::event('c', 'a'));
        $result = $this->app->handle(TestApp::event('c', 'fail'));

        self::assertTrue($result->isError());
        self::assertSame(['entered 1', 'stored a', self::INTERNAL_ERROR], $this->replies());
        self::assertSame(['a'], $this->session('c')->getList('inputs'));
        self::assertFalse($this->session('c')->has('touched'));
        self::assertSame(1, $this->session('c')->getInt('entries'));
    }

    public function testTransitionGuardsAreEnforcedAndRootAndBackAreAlwaysAllowed(): void
    {
        $this->registerMenuFlow();
        $this->app->allowTransition(MenuScene::class, DetailsScene::class, static fn(SceneContext $c): bool => $c->getBool('vip'));
        $this->app->allowTransition(RootScene::ID, MenuScene::class);
        $this->app->allowTransition(RootScene::ID, 'checkout');
        $this->app->onCommand('vip', static function (Context $ctx): void {
            $ctx->session()->set('vip', true);
        });

        $this->app->handle(TestApp::event('c', '/start'));
        self::assertFalse($this->app->getConversations()->resume('c')->canEnter(DetailsScene::class));

        $denied = $this->app->handle(TestApp::event('c', actionId: 'scene:onGoDetails'));
        self::assertTrue($denied->isError());
        self::assertSame(MenuScene::class, $this->currentScene('c'));

        $this->app->handle(TestApp::event('c', '/vip'));
        self::assertTrue($this->app->getConversations()->resume('c')->canEnter(DetailsScene::class));
        $this->app->handle(TestApp::event('c', actionId: 'scene:onGoDetails'));
        self::assertSame(DetailsScene::class, $this->currentScene('c'));

        $this->app->handle(TestApp::event('c', actionId: 'scene:onBack'));
        self::assertSame(MenuScene::class, $this->currentScene('c'), 'back is allowed without a declared edge');

        $this->app->handle(TestApp::event('c', actionId: 'scene:onClose'));
        self::assertSame(RootScene::ID, $this->currentScene('c'), 'leaving to root is always allowed');

        $denied = $this->app->handle(TestApp::event('c', '/details'));
        self::assertTrue($denied->isError(), 'root -> details is not declared');
        self::assertSame(RootScene::ID, $this->currentScene('c'));
    }

    public function testSceneMiddlewareRunsOnlyWhileTheSceneIsActive(): void
    {
        $this->app->registerScene(CustomMiddlewareScene::class);
        $this->app->onCommand('mw', static function (Context $ctx): void {
            $ctx->enter(CustomMiddlewareScene::class);
        });

        $this->app->handle(TestApp::event('c', '/mw'));
        self::assertSame(['CustomMiddlewareScene:handle'], $this->log->all());

        $this->log->clear();
        $this->app->handle(TestApp::event('c', 'next'));
        self::assertSame(['scene-middleware', 'CustomMiddlewareScene:handle'], $this->log->all());
    }

    public function testExpiredConversationsAreResetToRoot(): void
    {
        $this->app = $this->createApp(ttlSeconds: 60);
        $this->registerMenuFlow();
        $this->app->fallback(static function (Context $ctx): void {
            $ctx->reply('fallback');
        });

        $this->app->handle(TestApp::event('c', '/start'));
        $this->clock->advance(61);
        $this->app->handle(TestApp::event('c', 'anything'));

        self::assertSame('fallback', $this->lastReply());
        self::assertSame(RootScene::ID, $this->currentScene('c'));
    }

    public function testConversationsPointingToUnknownScenesAreReset(): void
    {
        $this->registerMenuFlow();
        $this->app->fallback(static function (Context $ctx): void {
            $ctx->reply('fallback');
        });
        $this->storage->save('c', StateSnapshot::create(['name' => 'Alex'], 'App\\Removed\\Scene', [], 3, new DateTimeImmutable())->toArray());
        $this->storage->save('legacy', ['meta' => ['current_scene' => MenuScene::class], 'data' => []]);

        $this->app->handle(TestApp::event('c', 'hi'));
        $this->app->handle(TestApp::event('legacy', 'hi'));

        self::assertSame(['fallback', 'fallback'], $this->replies());
        self::assertSame(RootScene::ID, $this->currentScene('c'));
        self::assertSame(RootScene::ID, $this->currentScene('legacy'));
    }

    public function testObserverRecordsSceneTransitions(): void
    {
        $this->registerMenuFlow();

        $this->app->handle(TestApp::event('c', '/start'));
        $this->app->handle(TestApp::event('c', actionId: 'scene:onGoDetails'));

        $names = $this->observer->getNames();
        self::assertContains('scene.entered', $names);
        self::assertContains('scene.left', $names);
        self::assertContains('scene.matched', $names);
    }

    public function testSceneContextTypedAccessorsAreAvailableToHandlers(): void
    {
        $this->app->onCommand('count', static function (Context $ctx): void {
            $ctx->session()->increment('visits');
            $ctx->session()->push('log', $ctx->getText());
            $ctx->reply(View::text((string) $ctx->session()->getInt('visits')));
        });

        $this->app->handle(TestApp::event('c', '/count'));
        $this->app->handle(TestApp::event('c', '/count again'));

        self::assertSame(['1', '2'], $this->replies());
        self::assertSame(['/count', '/count again'], $this->session('c')->getList('log'));
    }


    public function testScheduledEntryIsAppliedOnTheNextEventAndConsumesIt(): void
    {
        $this->registerMenuFlow();
        $this->app->fallback(static function (Context $ctx): void {
            $ctx->reply('fallback');
        });

        $this->app->getConversations()->enterLater('c', MenuScene::class, ['source' => 'scheduler'], title: 'Home');

        self::assertSame(['c'], $this->storage->keys(), 'the intent is persisted immediately');
        self::assertSame('enter', $this->app->getConversations()->getPending('c')['action'] ?? null);
        self::assertSame(RootScene::ID, $this->currentScene('c'), 'nothing moves until the next event');

        $result = $this->app->handle(TestApp::event('c', 'hello'));

        self::assertSame('scene_entered', $result->getMessage());
        self::assertSame(['Menu screen'], $this->replies());
        self::assertSame(['Menu:enter'], $this->log->all(), 'the triggering text is consumed, not handled');
        self::assertSame(MenuScene::class, $this->currentScene('c'));
        self::assertSame('scheduler', $this->session('c')->get('source'));
        self::assertNull($this->app->getConversations()->getPending('c'));
        self::assertContains('scene.pending_applied', $this->observer->getNames());
    }

    public function testScheduledEntryCanHandTheTriggeringEventToTheNewScene(): void
    {
        $this->registerMenuFlow();

        $this->app->getConversations()->enterLater('c', MenuScene::class, handleTrigger: true);
        $result = $this->app->handle(TestApp::event('c', 'hello'));

        self::assertSame('scene_processed', $result->getMessage());
        self::assertSame(['Menu:enter', 'Menu:handle:hello'], $this->log->all());
        self::assertSame(['Menu screen', 'Menu got hello'], $this->replies());
    }

    public function testScheduledLeaveReturnsToRootBeforeHandlingTheEvent(): void
    {
        $this->registerMenuFlow();
        $this->app->fallback(static function (Context $ctx): void {
            $ctx->reply('fallback');
        });

        $this->app->handle(TestApp::event('c', '/start'));
        $this->app->getConversations()->leaveLater('c');
        $this->log->clear();

        $this->app->handle(TestApp::event('c', 'hi'));

        self::assertSame(['Menu:leave'], $this->log->all());
        self::assertSame('fallback', $this->lastReply());
        self::assertSame(RootScene::ID, $this->currentScene('c'));
    }

    public function testScheduledEntryDeniedByTransitionsIsDroppedAndTheEventIsHandled(): void
    {
        $this->registerMenuFlow();
        $this->app->allowTransition(RootScene::ID, MenuScene::class);
        $this->app->fallback(static function (Context $ctx): void {
            $ctx->reply('fallback');
        });

        $this->app->getConversations()->enterLater('c', DetailsScene::class);
        $result = $this->app->handle(TestApp::event('c', 'hi'));

        self::assertTrue($result->isNoMatch() || $result->isSuccess());
        self::assertSame(['fallback'], $this->replies());
        self::assertSame(RootScene::ID, $this->currentScene('c'));
        self::assertNull($this->app->getConversations()->getPending('c'), 'a failed pending transition is dropped, not retried');
        self::assertContains('scene.pending_failed', $this->observer->getNames());
    }

    public function testScheduledEntryWhoseOnEnterFailsIsDroppedOnce(): void
    {
        $this->app->registerScene(BrokenScene::class);
        $this->app->fallback(static function (Context $ctx): void {
            $ctx->reply('fallback');
        });

        $this->app->getConversations()->enterLater('c', BrokenScene::class);
        $this->app->handle(TestApp::event('c', 'first'));
        $this->app->handle(TestApp::event('c', 'second'));

        self::assertSame(['fallback', 'fallback'], $this->replies());
        self::assertSame(RootScene::ID, $this->currentScene('c'));
        self::assertSame(1, \count(array_filter($this->observer->getNames(), static fn(string $name): bool => $name === 'scene.pending_failed')));
    }

    public function testScenesCanBeEnteredAndLeftImmediatelyFromOutside(): void
    {
        $this->registerMenuFlow();

        $entered = $this->app->enter('c', MenuScene::class, ['source' => 'cron']);

        self::assertTrue($entered->isSuccess());
        self::assertSame(['Menu screen'], $this->replies());
        self::assertSame(MenuScene::class, $this->currentScene('c'));
        self::assertSame('cron', $this->session('c')->get('source'));

        $this->app->run('c', static function (Context $ctx): void {
            $ctx->reply($ctx->isSystem() ? 'system tick in ' . $ctx->getCurrentScene() : 'user tick');
        });
        self::assertSame('system tick in ' . MenuScene::class, $this->lastReply());

        $left = $this->app->leave('c');

        self::assertTrue($left->isSuccess());
        self::assertSame(RootScene::ID, $this->currentScene('c'));
        self::assertContains('Menu:leave', $this->log->all());
    }

    public function testClearPendingForgetsAScheduledTransition(): void
    {
        $this->registerMenuFlow();
        $this->app->getConversations()->enterLater('c', MenuScene::class);
        $this->app->getConversations()->clearPending('c');
        $this->app->fallback(static function (Context $ctx): void {
            $ctx->reply('fallback');
        });

        $this->app->handle(TestApp::event('c', 'hi'));

        self::assertSame(['fallback'], $this->replies());
        self::assertSame(RootScene::ID, $this->currentScene('c'));
    }

    private function createApp(?int $ttlSeconds = null): Application
    {
        $container = new Container();
        $container->set(HookLog::class, $this->log);

        return TestApp::create(
            adapter: $this->adapter,
            storage: $this->storage,
            observer: $this->observer,
            container: $container,
            clock: $this->clock,
            sessionTtlSeconds: $ttlSeconds,
        );
    }

    private function registerMenuFlow(): void
    {
        $this->app->registerScene(MenuScene::class);
        $this->app->registerScene(DetailsScene::class);
        $this->app->registerScene(CheckoutScene::class);
        $this->app->onCommand('start', static function (Context $ctx): void {
            $ctx->enter(MenuScene::class);
        });
        $this->app->onCommand('details', static function (Context $ctx): void {
            $ctx->enter(DetailsScene::class);
        });
        $this->app->onCommand('checkout', static function (Context $ctx): void {
            $ctx->enter('checkout');
        });
        $this->app->onCommand('close', static function (Context $ctx): void {
            $ctx->leave();
        });
    }

    private function currentScene(string $conversationId): string
    {
        return $this->app->getConversations()->resume($conversationId)->getCurrentScene();
    }

    private function session(string $conversationId): SceneContext
    {
        return $this->app->getConversations()->resume($conversationId)->getContext();
    }

    private function lastReply(): string
    {
        $replies = $this->replies();

        return $replies === [] ? '' : $replies[\count($replies) - 1];
    }

    /**
     * @return list<string>
     */
    private function replies(): array
    {
        return array_map(static fn(View $view): string => $view->getText(), $this->adapter->replies);
    }
}
