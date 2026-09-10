<?php

declare(strict_types=1);

namespace ChatFlow\View;

use ChatFlow\Support\SerializableValueValidator;

final class MediaAttachment
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $meta;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string $type,
        private readonly string $source,
        array $meta = [],
    ) {
        $this->meta = SerializableValueValidator::normalizeMap($meta, 'media meta');
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }
}
