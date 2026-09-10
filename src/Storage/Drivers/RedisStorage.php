<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StorageInterface;
use JsonException;
use Redis;

/**
 * Redis storage. Requires the phpredis extension. Every record expires after the configured TTL.
 */
class RedisStorage implements StorageInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'chatflow:',
        private readonly int $ttlSeconds = 86400,
    ) {}

    public function get(string $key): ?array
    {
        $raw = $this->redis->get($this->prefix . $key);

        if (!\is_string($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $record = [];

        foreach ($decoded as $recordKey => $value) {
            $record[(string) $recordKey] = $value;
        }

        return $record;
    }

    public function save(string $key, array $data): void
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode record: ' . $e->getMessage(), 0, $e);
        }

        if ($this->redis->setex($this->prefix . $key, $this->ttlSeconds, $json) !== true) {
            throw new StorageException(\sprintf('Failed to write record "%s" to Redis.', $key));
        }
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function exists(string $key): bool
    {
        return (bool) $this->redis->exists($this->prefix . $key);
    }
}
