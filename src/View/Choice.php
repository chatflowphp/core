<?php

declare(strict_types=1);

namespace ChatFlow\View;

use ChatFlow\Support\SerializableValueValidator;

/**
 * A quick-reply option: pressing it sends its value as a text message.
 */
final class Choice
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $meta;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly string $label,
        private readonly string $value,
        array $meta = [],
    ) {
        $this->meta = SerializableValueValidator::normalizeMap($meta, 'choice meta');
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
        return $this->meta;
    }
}
