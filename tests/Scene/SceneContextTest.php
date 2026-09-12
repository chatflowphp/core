<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Scene;

use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\SceneException;
use ChatFlow\Scene\Interaction;
use ChatFlow\Scene\SceneContext;
use ChatFlow\Testing\FakePlatformAdapter;
use ChatFlow\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class SceneContextTest extends TestCase
{
    public function testReservedKeysCannotBeWrittenOrRemovedDirectly(): void
    {
        $context = new SceneContext();

        try {
            $context->set('_history', []);
            self::fail('Expected a LogicException.');
        } catch (LogicException $e) {
            self::assertStringContainsString('reserved', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $context->remove('_interaction');
    }

    public function testAllAndKeysHideRuntimeDataWhileGetStateKeepsIt(): void
    {
        $context = new SceneContext();
        $context->set('name', 'Alex');
        $context->pushHistory('menu');
        $context->setExtension('screens', ['admin' => [1, 2]]);

        self::assertSame(['name' => 'Alex'], $context->all());
        self::assertSame(['name'], $context->keys());
        self::assertSame(['name', '_history', '_ext.screens'], array_keys($context->getState()));

        $context->clear();

        self::assertSame([], $context->all());
        self::assertSame([['scene' => 'menu', 'title' => null]], $context->getHistory());
        self::assertSame(['admin' => [1, 2]], $context->getExtension('screens'));
    }

    public function testHistoryIsAStackThatIgnoresConsecutiveDuplicates(): void
    {
        $context = new SceneContext();

        self::assertNull($context->popHistory());
        self::assertNull($context->peekHistory());

        $context->pushHistory('menu', 'Menu');
        $context->pushHistory('menu', 'Menu again');
        $context->pushHistory('details');
        $context->updateHistoryTitle('Details');

        self::assertSame([
            ['scene' => 'menu', 'title' => 'Menu'],
            ['scene' => 'details', 'title' => 'Details'],
        ], $context->getHistory());
        self::assertSame(['scene' => 'details', 'title' => 'Details'], $context->peekHistory());
        self::assertSame(['scene' => 'details', 'title' => 'Details'], $context->popHistory());
        self::assertSame(['scene' => 'menu', 'title' => 'Menu'], $context->popHistory());
        self::assertNull($context->popHistory());

        $context->pushHistory('x');
        $context->clearHistory();
        self::assertSame([], $context->getHistory());
    }

    public function testCorruptedRuntimeDataIsIgnored(): void
    {
        $context = new SceneContext();
        $context->setState([
            '_history' => ['garbage', ['scene' => 1], ['scene' => 'ok', 'title' => 5]],
            '_interaction' => ['validators' => 'nope'],
            '_ext.screens' => [0 => 'list', 'group' => 'x'],
        ]);

        self::assertSame([['scene' => 'ok', 'title' => null]], $context->getHistory());
        self::assertNull($context->getInteraction());
        self::assertFalse($context->hasInteraction());
        self::assertSame(['group' => 'x'], $context->getExtension('screens'));
    }

    public function testInteractionRoundTrip(): void
    {
        $context = new SceneContext();
        $interaction = new Interaction($context);
        $interaction->validate('required', 'Required')->onText('cancel', 'onCancel')->handle('save');

        self::assertSame([
            'validators' => [['rule' => 'required', 'error' => 'Required']],
            'fallbacks' => [['type' => 'text', 'pattern' => 'cancel', 'handler' => 'onCancel']],
            'handler' => 'save',
        ], $context->getInteraction());

        $context->clearInteraction();
        self::assertFalse($context->hasInteraction());
    }

    public function testEmptyExtensionsAreRemoved(): void
    {
        $context = new SceneContext();
        $context->setExtension('screens', ['a' => 1]);
        $context->setExtension('screens', []);

        self::assertSame([], $context->getExtension('screens'));
        self::assertSame([], $context->getState());
    }

    public function testRequestBindingIsTransient(): void
    {
        $context = new SceneContext();
        $request = new Context(TestApp::event('c'), new FakePlatformAdapter(), new Container());

        self::assertFalse($context->hasRequest());
        $context->bindRequest($request);
        self::assertSame($request, $context->request());
        self::assertSame([], $context->getState());
        $context->unbindRequest();

        $this->expectException(SceneException::class);
        $context->request();
    }
}
