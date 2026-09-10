<?php

declare(strict_types=1);

namespace ChatFlow\Event;

use ChatFlow\Support\SerializableValueValidator;
use InvalidArgumentException;

final class UserRef
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $meta;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string|int $id,
        private readonly ?string $platform = null,
        array $meta = [],
    ) {
        if (\is_string($id) && trim($id) === '') {
            throw new InvalidArgumentException('User id must be a non-empty string or an integer.');
        }

        $this->meta = SerializableValueValidator::normalizeMap($meta, 'user meta');
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
        return $this->meta;
    }
}
