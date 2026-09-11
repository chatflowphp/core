<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support\Scenes;

use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;
use RuntimeException;

/**
 * A scene that cannot be entered: onEnter() always fails.
 */
final class BrokenScene extends BaseScene
{
    public function onEnter(Context $ctx): void
    {
        $ctx->reply('this reply must never be delivered');

        throw new RuntimeException('cannot render the broken scene');
    }

    public function handle(Context $ctx): void
    {
        $ctx->reply('broken handle');
    }
}
