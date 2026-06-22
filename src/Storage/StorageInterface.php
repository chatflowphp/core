<?php

declare(strict_types=1);

namespace ChatFlow\Storage;

use ChatFlow\Exception\StorageException;

/**
 * Interface for storage drivers.
 */
interface StorageInterface
{
    /**
     * @return array<string, mixed>|null
     *
     * @throws StorageException If storage operation fails
     */
    public function get(string $conversationId): ?array;

    /**
     * @param array<string, mixed> $data
     *
     * @throws StorageException If storage operation fails
     */
    public function save(string $conversationId, array $data): void;

    /**
     * @throws StorageException If storage operation fails
     */
    public function delete(string $conversationId): void;

    /**
     * @throws StorageException If storage operation fails
     */
    public function exists(string $conversationId): bool;
}
