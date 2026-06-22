<?php

declare(strict_types=1);

namespace ChatFlow\Support;

use BackedEnum;
use InvalidArgumentException;
use UnitEnum;

final class SerializableValueValidator
{
    public static function assertSerializable(mixed $value, string $path = 'value'): void
    {
        self::normalize($value, $path);
    }

    public static function normalize(mixed $value, string $path = 'value'): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            throw new InvalidArgumentException("{$path} contains a non-backed enum.");
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException("{$path} must contain only serializable scalar, null, array or BackedEnum values.");
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalize($item, "{$path}.{$key}");
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function normalizeMap(array $value, string $path = 'value'): array
    {
        $normalized = self::normalize($value, $path);
        if (!is_array($normalized) || ($normalized !== [] && array_is_list($normalized))) {
            throw new InvalidArgumentException("{$path} must be an associative array.");
        }

        /* @var array<string, mixed> $normalized */
        return $normalized;
    }
}
