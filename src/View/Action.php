<?php

declare(strict_types=1);

namespace ChatFlow\View;

use ChatFlow\Support\SerializableValueValidator;

final class Action
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly mixed $payload = null,
        private readonly ?string $url = null,
        private readonly array $meta = [],
    ) {
        SerializableValueValidator::assertSerializable($this->payload, 'action payload');
        SerializableValueValidator::assertSerializable($this->meta, 'action meta');
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
        return SerializableValueValidator::normalize($this->payload, 'action payload');
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
        return SerializableValueValidator::normalizeMap($this->meta, 'action meta');
    }
}
