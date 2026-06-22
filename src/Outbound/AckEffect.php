<?php

declare(strict_types=1);

namespace ChatFlow\Outbound;

final class AckEffect implements OutboundEffectInterface
{
    public function __construct(
        private readonly ?string $text = null,
        private readonly bool $error = false,
    ) {
    }

    public function getType(): string
    {
        return 'ack';
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function isError(): bool
    {
        return $this->error;
    }
}
