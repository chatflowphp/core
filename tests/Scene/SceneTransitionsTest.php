<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Scene;

use ChatFlow\Container\Container;
use ChatFlow\Scene\RootScene;
use ChatFlow\Scene\SceneContext;
use ChatFlow\Scene\SceneRegistry;
use ChatFlow\Scene\SceneTransitions;
use ChatFlow\Tests\Support\HookLog;
use ChatFlow\Tests\Support\Scenes\CheckoutScene;
use ChatFlow\Tests\Support\Scenes\DetailsScene;
use ChatFlow\Tests\Support\Scenes\MenuScene;
use PHPUnit\Framework\TestCase;

final class SceneTransitionsTest extends TestCase
{
    private SceneRegistry $scenes;

    protected function setUp(): void
    {
        $container = new Container();
        $container->set(HookLog::class, new HookLog());
        $this->scenes = new SceneRegistry($container);
        $this->scenes->register(MenuScene::class);
        $this->scenes->register(DetailsScene::class);
        $this->scenes->register(CheckoutScene::class);
    }

    public function testEverythingIsAllowedUntilRestricted(): void
    {
        $transitions = new SceneTransitions($this->scenes);

        self::assertFalse($transitions->isRestricted());
        self::assertTrue($transitions->isAllowed(MenuScene::class, DetailsScene::class, new SceneContext()));
        self::assertSame([], $transitions->edges());
    }

    public function testDeclaredEdgesGuardsWildcardsAndRootRule(): void
    {
        $transitions = (new SceneTransitions($this->scenes))
            ->allow(RootScene::ID, MenuScene::class)
            ->allow(MenuScene::class, DetailsScene::class, static fn(SceneContext $c): bool => $c->getBool('vip'))
            ->allow(SceneTransitions::ANY, CheckoutScene::class);
        $context = new SceneContext();

        self::assertTrue($transitions->isRestricted());
        self::assertTrue($transitions->isAllowed(RootScene::ID, MenuScene::class, $context));
        self::assertFalse($transitions->isAllowed(RootScene::ID, DetailsScene::class, $context));
        self::assertFalse($transitions->isAllowed(MenuScene::class, DetailsScene::class, $context));

        $context->set('vip', true);
        self::assertTrue($transitions->isAllowed(MenuScene::class, DetailsScene::class, $context));

        self::assertTrue($transitions->isAllowed(DetailsScene::class, 'checkout', $context), 'wildcard source');
        self::assertTrue($transitions->isAllowed(DetailsScene::class, RootScene::ID, $context), 'root is always reachable');
        self::assertFalse($transitions->isAllowed(DetailsScene::class, MenuScene::class, $context));

        $context->markReturningTo(MenuScene::class);
        self::assertTrue($transitions->isAllowed(DetailsScene::class, MenuScene::class, $context), 'going back is always allowed');
        $context->markReturningTo(null);
        self::assertFalse($transitions->isAllowed(DetailsScene::class, MenuScene::class, $context));
    }

    public function testDefineAcceptsDeclarativeMapsAndResolvesIdsLazily(): void
    {
        $transitions = (new SceneTransitions($this->scenes))->define([
            RootScene::ID => [MenuScene::class],
            MenuScene::class => [CheckoutScene::class => static fn(SceneContext $c): bool => $c->has('cart')],
        ]);

        self::assertSame([
            RootScene::ID => [MenuScene::class],
            MenuScene::class => ['checkout'],
        ], $transitions->edges());

        $mermaid = $transitions->toMermaid();
        self::assertStringContainsString('stateDiagram-v2', $mermaid);
        self::assertStringContainsString('checkout : guarded', $mermaid);
    }
}
