<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Storage\StorageInterface;

/**
 * In-memory storage driver.
 * Useful for testing and stateless environments.
 * Data is lost when the script terminates.
 */
class MemoryStorage implements StorageInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $storage = [];

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $conversationId): ?array
    {
        return $this->storage[$conversationId] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $conversationId, array $data): void
    {
        $this->storage[$conversationId] = $data;
    }

    public function delete(string $conversationId): void
    {
        unset($this->storage[$conversationId]);
    }

    public function exists(string $conversationId): bool
    {
        return isset($this->storage[$conversationId]);
    }

    public function clearAll(): void
    {
        $this->storage = [];
    }
}
