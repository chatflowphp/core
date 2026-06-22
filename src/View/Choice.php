<?php

declare(strict_types=1);

namespace ChatFlow\View;

use ChatFlow\Support\SerializableValueValidator;

final class Choice
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string $label,
        private readonly string $value,
        private readonly array $meta = [],
    ) {
        SerializableValueValidator::assertSerializable($this->meta, 'choice meta');
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return SerializableValueValidator::normalizeMap($this->meta, 'choice meta');
    }
}
