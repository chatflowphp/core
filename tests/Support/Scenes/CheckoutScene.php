<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support\Scenes;

use ChatFlow\Core\Context;

/**
 * Scene with a custom id, an interaction that supports a text shortcut, and no global routes.
 */
final class CheckoutScene extends RecordingScene
{
    public function getId(): string
    {
        return 'checkout';
    }

    public function allowsGlobalRoutes(): bool
    {
        return false;
    }

    public function onEnter(Context $ctx): void
    {
        $this->log->add('Checkout:enter');
        $ctx->ask('Phone?')
            ->validate('regex:/^\+\d+$/', 'Digits only')
            ->onText(['cancel', '/^stop/i'], 'onCancel')
            ->onMedia('photo', 'onPhoto')
            ->handle('savePhone');
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('phone', $ctx->getText());
        $ctx->reply('Saved');
        $ctx->leave();
    }

    public function onCancel(Context $ctx): void
    {
        $this->log->add('Checkout:cancel');
        $ctx->reply('Cancelled');
        $ctx->back();
    }

    public function onPhoto(Context $ctx): void
    {
        $this->log->add('Checkout:photo');
        $ctx->reply('Got a photo');
    }
}
