<?php

declare(strict_types=1);

namespace ChatFlow\Event;

use ChatFlow\Support\SerializableValueValidator;
use InvalidArgumentException;

final class ConversationRef
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string $id,
        private readonly ?string $platform = null,
        private readonly array $meta = [],
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Conversation id must be a non-empty string.');
        }

        SerializableValueValidator::assertSerializable($this->meta, 'conversation meta');
    }

    public static function fromId(string|int $id, ?string $platform = null): self
    {
        return new self((string) $id, $platform);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPlatform(): ?string
    {
        return $this->platform;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return SerializableValueValidator::normalizeMap($this->meta, 'conversation meta');
    }
}
