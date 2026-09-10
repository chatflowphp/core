<?php

declare(strict_types=1);

namespace ChatFlow\View;

use ChatFlow\Support\SerializableValueValidator;

/**
 * A button that sends an action id (and optional payload) back to the bot, or opens a URL.
 */
final class Action
{
    private readonly mixed $payload;

    /**
     * @var array<string, mixed>
     */
    private readonly array $meta;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        mixed $payload = null,
        private readonly ?string $url = null,
        array $meta = [],
    ) {
        $this->payload = SerializableValueValidator::normalize($payload, 'action payload');
        $this->meta = SerializableValueValidator::normalizeMap($meta, 'action meta');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }
}
