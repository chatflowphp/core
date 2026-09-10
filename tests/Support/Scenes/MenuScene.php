<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support\Scenes;

use ChatFlow\Core\Context;

final class MenuScene extends RecordingScene
{
    /**
     * @param array<string, mixed> $params
     */
    public function onOpen(Context $ctx, array $params = [], string $section = 'home'): void
    {
        $this->log->add('Menu:onOpen:' . $section . ':' . json_encode($params, JSON_THROW_ON_ERROR));
        $ctx->reply('Section ' . $section);
    }

    public function onGoDetails(Context $ctx): void
    {
        $ctx->enter(DetailsScene::class, ['item' => 42], 'Menu');
    }

    public function onGoCheckout(Context $ctx): void
    {
        $ctx->enter(CheckoutScene::class);
    }

    public function onRefresh(Context $ctx): void
    {
        $ctx->enter(self::class);
    }

    public function onSelfDestruct(Context $ctx): void
    {
        $ctx->session()->set('poisoned', true);
        $ctx->reply('half done');
        $ctx->enter(DetailsScene::class);

        throw new \RuntimeException('boom after transition');
    }

    public function onClose(Context $ctx): void
    {
        $ctx->leave();
    }

    protected function helper(): void {}
}
