<?php

declare(strict_types=1);

namespace ChatFlow\Tests\I18n;

use ChatFlow\I18n\ArrayTranslator;
use PHPUnit\Framework\TestCase;

final class ArrayTranslatorTest extends TestCase
{
    public function testTranslatesInTheRequestedLocale(): void
    {
        $translator = new ArrayTranslator([
            'en' => ['menu.title' => 'Menu'],
            'ru' => ['menu.title' => 'Меню'],
        ]);

        self::assertSame('Меню', $translator->trans('menu.title', [], 'ru'));
        self::assertSame('Menu', $translator->trans('menu.title', [], 'en'));
    }

    public function testFallsBackToThePrimarySubtagThenToTheFallbackLocale(): void
    {
        $translator = new ArrayTranslator([
            'en' => ['menu.title' => 'Menu', 'only.english' => 'English only'],
            'ru' => ['menu.title' => 'Меню'],
        ]);

        self::assertSame('Меню', $translator->trans('menu.title', [], 'ru-RU'), 'ru-RU uses the ru catalogue.');
        self::assertSame('English only', $translator->trans('only.english', [], 'ru'), 'Missing entries use the fallback locale.');
        self::assertSame('Menu', $translator->trans('menu.title', [], 'de'), 'Unknown locales use the fallback locale.');
        self::assertSame('Menu', $translator->trans('menu.title'), 'Without a locale the fallback is used.');
    }

    public function testMissingMessagesReturnTheId(): void
    {
        $translator = new ArrayTranslator(['en' => []]);

        self::assertSame('cart.empty', $translator->trans('cart.empty', [], 'en'));
    }

    public function testInterpolatesParameters(): void
    {
        $translator = new ArrayTranslator([
            'en' => ['order.placed' => 'Order #{id} for {name}: {total} EUR, gift: {gift}, note: {note}'],
        ]);

        self::assertSame(
            'Order #42 for Alex: 19.9 EUR, gift: 1, note: ',
            $translator->trans('order.placed', ['id' => 42, 'name' => 'Alex', 'total' => 19.9, 'gift' => true, 'note' => null], 'en'),
        );
    }

    public function testLocalesAreNormalizedAndCataloguesCanBeExtended(): void
    {
        $translator = (new ArrayTranslator(['EN' => ['a' => 'A']]))
            ->add('ru_RU', ['a' => 'А'])
            ->add('ru-ru', ['b' => 'Б']);

        self::assertSame(['en', 'ru-ru'], $translator->locales());
        self::assertSame('А', $translator->trans('a', [], 'ru-RU'));
        self::assertSame('Б', $translator->trans('b', [], 'ru_ru'));
    }

    public function testFallbackLocaleIsConfigurable(): void
    {
        $translator = new ArrayTranslator(['ru' => ['menu.title' => 'Меню']], fallbackLocale: 'ru');

        self::assertSame('Меню', $translator->trans('menu.title', [], 'fr'));
    }
}
