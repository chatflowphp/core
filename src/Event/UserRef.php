<?php

declare(strict_types=1);

namespace ChatFlow\Event;

use ChatFlow\Support\SerializableValueValidator;
use InvalidArgumentException;

final class UserRef
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string|int $id,
        private readonly ?string $platform = null,
        private readonly array $meta = [],
    ) {
        if (is_string($id) && trim($id) === '') {
            throw new InvalidArgumentException('User id must be a non-empty string or integer.');
        }

        SerializableValueValidator::assertSerializable($this->meta, 'user meta');
    }

    public function getId(): string|int
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
        return SerializableValueValidator::normalizeMap($this->meta, 'user meta');
    }
}
