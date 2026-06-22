<?php

declare(strict_types=1);

namespace ChatFlow\Outbound;

interface OutboundEffectInterface
{
    public function getType(): string;
}
