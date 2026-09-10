<?php

declare(strict_types=1);

namespace ChatFlow\Support;

use BackedEnum;
use InvalidArgumentException;
use UnitEnum;

/**
 * Enforces the serialization rules shared by events, views and session data: scalars, null,
 * arrays of those and backed enums (stored as their backing value). Objects, resources and
 * non-backed enums are rejected.
 */
final class SerializableValueValidator
{
    public static function assertSerializable(mixed $value, string $path = 'value'): void
    {
        self::normalize($value, $path);
    }

    public static function normalize(mixed $value, string $path = 'value'): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            throw new InvalidArgumentException(\sprintf('%s contains a non-backed enum.', $path));
        }

        if (!\is_array($value)) {
            throw new InvalidArgumentException(\sprintf(
                '%s must contain only scalar, null, array or BackedEnum values, got %s.',
                $path,
                get_debug_type($value),
            ));
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalize($item, $path . '.' . $key);
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function normalizeMap(array $value, string $path = 'value'): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new InvalidArgumentException(\sprintf('%s must be an associative array with string keys.', $path));
            }

            $normalized[$key] = self::normalize($item, $path . '.' . $key);
        }

        return $normalized;
    }
}
