<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Core;

use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\SideEffect\SideEffect;
use ChatFlow\Storage\Drivers\MemoryStreamStorage;
use ChatFlow\Storage\StreamRecord;
use ChatFlow\Storage\StreamStorageInterface;
use ChatFlow\Testing\ApplicationTester;
use ChatFlow\Testing\FakePlatformAdapter;
use ChatFlow\Tests\Support\Scenes\ThinkingScene;
use ChatFlow\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class LongTurnTest extends TestCase
{
    public function testSlowWorkRunsOutsideTheTickAndCatchesUpWithMessagesThatArrivedMeanwhile(): void
    {
        $streams = new MemoryStreamStorage();
        $container = new Container();
        $container->set(StreamStorageInterface::class, $streams);
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter, container: $container);
        $application->registerScene(ThinkingScene::class);
        $tester = new ApplicationTester($application, $adapter);
        $thinking = 0;

        $application->registerSideEffect('think', static function (SideEffect $effect) use ($streams, $application, &$thinking): array {
            $thinking++;
            $upto = $effect->payload['upto'];
            $texts = array_map(static fn(StreamRecord $record): string => \is_string($record->data['text'] ?? null) ? $record->data['text'] : '', $streams->read('conv-1:inbox'));

            // The user writes again while the model is "thinking".
            if ($thinking === 1) {
                $application->handle(TestApp::event('conv-1', 'second'));
            }

            return ['upto' => $upto, 'answer' => 'Answering ' . implode('+', \array_slice($texts, 0, \is_int($upto) ? $upto : 0))];
        });

        $application->enter('conv-1', 'thinking');
        $tester->send('first');

        self::assertSame(2, $thinking, 'The second message did not start its own generation; the first round scheduled a catch-up.');
        self::assertSame(['Answering first', 'Answering first+second'], $tester->getTexts());
        $tester->assertSessionHas('thinking', false)->assertNoSideEffectsPending();
        self::assertSame(2, $streams->last('conv-1:inbox'), 'Nothing was lost.');
    }

    public function testRunWithAnExpectedTickRefusesAStaleResult(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter);
        $seen = null;
        $application->onCommand('start', static function (Context $ctx) use (&$seen): void {
            $seen = $ctx->getTickCount();
            $ctx->session()->set('v', 1);
        });
        $application->onCommand('touch', static function (Context $ctx): void {
            $ctx->session()->set('v', 2);
        });

        $application->handle(TestApp::event('conv-1', '/start'));
        self::assertSame(1, $seen);

        $fresh = $application->run('conv-1', static function (Context $ctx): void {
            $ctx->reply('applied on ' . $ctx->getTickCount());
        }, 'apply', expectedTick: 1);
        self::assertTrue($fresh->isSuccess());
        self::assertSame('applied on 2', $adapter->replies[0]->getText());

        $application->handle(TestApp::event('conv-1', '/touch'));

        $stale = $application->run('conv-1', static function (Context $ctx): void {
            $ctx->reply('must not happen');
        }, 'apply', expectedTick: 2);

        self::assertTrue($stale->isError());
        self::assertSame('conversation_moved', $stale->getMessage());
        self::assertSame(['expected_tick' => 2, 'tick' => 3], $stale->getData());
        self::assertCount(1, $adapter->replies);
        self::assertSame(2, $application->getConversations()->resume('conv-1')->getContext()->get('v'));
    }
}
