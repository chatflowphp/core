<?php

declare(strict_types=1);

namespace ChatFlow\Tests\View;

use ChatFlow\View\Action;
use ChatFlow\View\Choice;
use ChatFlow\View\MediaAttachment;
use ChatFlow\View\View;
use ChatFlow\View\ViewSerializer;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    public function testViewsAreImmutableAndSerializable(): void
    {
        $base = View::text('Hello');
        $full = $base
            ->addActionRow(new Action('a:1', 'One', ['id' => 1]), new Action('docs', 'Docs', url: 'https://example.com'))
            ->addChoiceRow(new Choice('Yes', 'yes'))
            ->addMedia(new MediaAttachment('image', 'https://example.com/i.png', ['alt' => 'x']))
            ->withMeta(['telegram' => ['parse_mode' => 'HTML']])
            ->withText('Updated');

        self::assertSame('Hello', $base->getText());
        self::assertSame([], $base->getActions());
        self::assertSame('Updated', $full->getText());
        self::assertCount(1, $full->getActions());
        self::assertCount(2, $full->getActions()[0]);
        self::assertSame('https://example.com', $full->getActions()[0][1]->getUrl());

        $serialized = (new ViewSerializer())->serialize($full);

        self::assertSame('Updated', $serialized['text']);
        self::assertSame(['id' => 1], $serialized['actions'][0][0]['payload']);
        self::assertSame('yes', $serialized['choices'][0][0]['value']);
        self::assertSame('image', $serialized['media'][0]['type']);
        self::assertSame(['alt' => 'x'], $serialized['media'][0]['meta']);
        self::assertSame(['telegram' => ['parse_mode' => 'HTML']], $serialized['meta']);
    }

    public function testWithMethodsReplaceCollections(): void
    {
        $view = View::text('x')
            ->addActionRow(new Action('a', 'A'))
            ->withActions([[new Action('b', 'B')]])
            ->withChoices([[new Choice('C', 'c')]])
            ->withMedia([new MediaAttachment('image', 'src')]);

        self::assertSame('b', $view->getActions()[0][0]->getId());
        self::assertSame('c', $view->getChoices()[0][0]->getValue());
        self::assertSame('src', $view->getMedia()[0]->getSource());
    }
}
