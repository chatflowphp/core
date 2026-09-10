<?php

declare(strict_types=1);

namespace ChatFlow\Event;

use ChatFlow\Support\SerializableValueValidator;
use InvalidArgumentException;

final class InboundAttachment
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
        private readonly ?string $id = null,
        private readonly ?string $source = null,
        private readonly ?string $name = null,
        private readonly ?string $mimeType = null,
        private readonly ?int $size = null,
        array $meta = [],
    ) {
        if (trim($type) === '') {
            throw new InvalidArgumentException('Attachment type must be a non-empty string.');
        }

        $this->meta = SerializableValueValidator::normalizeMap($meta, 'attachment meta');
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
