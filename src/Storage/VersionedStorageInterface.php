<?php

declare(strict_types=1);

namespace ChatFlow\Storage;

use ChatFlow\Exception\StorageException;

/**
 * A driver that can write a record only when nobody changed it in the meantime.
 *
 * Conversations need this: two webhook workers handling two taps of the same button both read the
 * snapshot, both tick, and with a plain save() the slower one silently overwrites the faster one.
 */
interface VersionedStorageInterface extends StorageInterface
{
    /**
     * Writes the record only when the stored one still carries the expected version.
     *
     * @param array<string, mixed> $data
     * @param string $versionKey Record field holding the version, for example "tickCount"
     * @param int|null $expectedVersion Version read before the change; null when the record must not exist yet
     *
     * @return bool False when the stored record moved on and nothing was written
     *
     * @throws StorageException
     */
    public function saveIfVersion(string $key, array $data, string $versionKey, ?int $expectedVersion): bool;
}
