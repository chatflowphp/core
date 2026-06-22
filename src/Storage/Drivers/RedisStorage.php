<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StorageInterface;
use JsonException;
use Redis;

/**
 * Redis storage driver for high-load environments.
 * Requires 'ext-redis' PHP extension.
 */
class RedisStorage implements StorageInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'chatflow:',
        private readonly int $ttl = 86400
    ) {
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws StorageException If JSON decoding fails
     */
    public function get(string $conversationId): ?array
    {
        $key = $this->prefix . $conversationId;
        /** @var string|false $data */
        $data = $this->redis->get($key);

        if ($data === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $result */
            $result = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

            return $result;
        } catch (JsonException $e) {
            return null;
        }
    }

    /**
     * Save session data to Redis.
     *
     * @param array<string, mixed> $data
     *
     * @throws StorageException If encoding fails or Redis operation fails
     */
    public function save(string $conversationId, array $data): void
    {
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode session data for Redis: ' . $e->getMessage(), 0, $e);
        }

        $key = $this->prefix . $conversationId;
        /** @var bool|Redis $success */
        $success = $this->redis->setex($key, $this->ttl, $json);

        if ($success !== true) {
            throw new StorageException("Failed to write session to Redis for conversation: {$conversationId}");
        }
    }

    public function delete(string $conversationId): void
    {
        $this->redis->del($this->prefix . $conversationId);
    }

    public function exists(string $conversationId): bool
    {
        /** @var int|bool $result */
        $result = $this->redis->exists($this->prefix . $conversationId);

        return (bool) $result;
    }
}
