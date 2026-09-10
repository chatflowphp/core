<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Scene;

use ChatFlow\Container\Container;
use ChatFlow\Exception\SceneException;
use ChatFlow\Exception\SceneNotFoundException;
use ChatFlow\Scene\SceneRegistry;
use ChatFlow\Tests\Support\HookLog;
use ChatFlow\Tests\Support\Scenes\CheckoutScene;
use ChatFlow\Tests\Support\Scenes\MenuScene;
use PHPUnit\Framework\TestCase;

final class SceneRegistryTest extends TestCase
{
    private SceneRegistry $registry;

    protected function setUp(): void
    {
        $container = new Container();
        $container->set(HookLog::class, new HookLog());
        $this->registry = new SceneRegistry($container);
    }

    public function testScenesAreResolvedByClassOrIdAndInstantiatedOnce(): void
    {
        $this->registry->register(MenuScene::class, 'Menu');
        $this->registry->register(CheckoutScene::class);

        self::assertSame([MenuScene::class, CheckoutScene::class], $this->registry->classes());
        self::assertSame('Menu', $this->registry->getLabel(MenuScene::class));
        self::assertNull($this->registry->getLabel(CheckoutScene::class));
        self::assertTrue($this->registry->has(MenuScene::class));
        self::assertTrue($this->registry->has('checkout'));
        self::assertFalse($this->registry->has('unknown'));

        self::assertSame($this->registry->get(MenuScene::class), $this->registry->get(MenuScene::class));
        self::assertSame($this->registry->get(CheckoutScene::class), $this->registry->get('checkout'));
        self::assertSame('checkout', $this->registry->resolveId(CheckoutScene::class));
        self::assertSame(MenuScene::class, $this->registry->resolveId(MenuScene::class));
        self::assertSame([MenuScene::class, 'checkout'], array_keys($this->registry->all()));
    }

    public function testUnknownScenesThrow(): void
    {
        $this->expectException(SceneNotFoundException::class);

        $this->registry->get('nope');
    }

    public function testNonSceneClassesAreRejected(): void
    {
        $this->expectException(SceneException::class);

        /** @phpstan-ignore argument.type */
        $this->registry->register(\stdClass::class);
    }

    public function testMissingClassesAreRejected(): void
    {
        $this->expectException(SceneException::class);

        /** @phpstan-ignore argument.type */
        $this->registry->register('App\\Missing\\Scene');
    }
}
