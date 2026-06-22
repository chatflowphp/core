<?php

declare(strict_types=1);

namespace ChatFlow\Outbound;

use ChatFlow\View\View;

final class ReplyEffect implements OutboundEffectInterface
{
    public function __construct(
        private readonly View $view,
    ) {
    }

    public function getType(): string
    {
        return 'reply';
    }

    public function getView(): View
    {
        return $this->view;
    }
}
