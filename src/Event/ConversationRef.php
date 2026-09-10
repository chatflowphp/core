<?php

declare(strict_types=1);

namespace ChatFlow\Event;

use ChatFlow\Support\SerializableValueValidator;
use InvalidArgumentException;

/**
 * Identifies the conversation an event belongs to. The id is the storage key of the conversation.
 */
final class ConversationRef
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $meta;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string $id,
        private readonly ?string $platform = null,
        array $meta = [],
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Conversation id must be a non-empty string.');
        }

        $this->meta = SerializableValueValidator::normalizeMap($meta, 'conversation meta');
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
        return $this->meta;
    }
}
