<?php

declare(strict_types=1);

namespace ChatFlow\Storage;

/**
 * Compares the version a record carried when it was read with the one stored now. Drivers use it
 * to implement compare-and-swap writes.
 */
final class RecordVersion
{
    /**
     * @param array<string, mixed>|null $record The record as stored right now
     * @param string $versionKey Field holding the version, for example "tickCount"
     * @param int|null $expected Version read before the change; null means the record must not exist
     */
    public static function matches(?array $record, string $versionKey, ?int $expected): bool
    {
        if ($record === null) {
            return $expected === null;
        }

        if ($expected === null) {
            return false;
        }

        $current = $record[$versionKey] ?? null;

        return \is_int($current) && $current === $expected;
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function of(array $record, string $versionKey): ?int
    {
        $version = $record[$versionKey] ?? null;

        return \is_int($version) ? $version : null;
    }
}
