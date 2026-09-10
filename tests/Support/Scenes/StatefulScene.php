<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support\Scenes;

use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;

/**
 * Counts entries in the session to prove that rollback restores session data.
 */
final class StatefulScene extends BaseScene
{
    public function onEnter(Context $ctx): void
    {
        $ctx->session()->increment('entries');
        $ctx->reply('entered ' . $ctx->session()->getInt('entries'));
    }

    public function handle(Context $ctx): void
    {
        if ($ctx->getText() === 'fail') {
            $ctx->session()->set('touched', true);
            $ctx->reply('will be dropped');

            throw new \DomainException('scene failure');
        }

        $ctx->session()->push('inputs', $ctx->getText());
        $ctx->reply('stored ' . $ctx->getText());
    }
}
