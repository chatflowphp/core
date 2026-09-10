<?php

declare(strict_types=1);

namespace ChatFlow\Storage;

use ChatFlow\Exception\StorageException;

/**
 * Key-value storage for JSON-compatible records. Drivers store records exactly as given.
 */
interface StorageInterface
{
    /**
     * @return array<string, mixed>|null
     *
     * @throws StorageException
     */
    public function get(string $key): ?array;

    /**
     * @param array<string, mixed> $data
     *
     * @throws StorageException
     */
    public function save(string $key, array $data): void;

    /**
     * @throws StorageException
     */
    public function delete(string $key): void;

    /**
     * @throws StorageException
     */
    public function exists(string $key): bool;
}
