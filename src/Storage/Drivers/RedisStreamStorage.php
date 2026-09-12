<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StreamRecord;
use ChatFlow\Storage\StreamStorageInterface;
use JsonException;
use Redis;

/**
 * Redis streams as a list per stream key plus a hash of deduplication ids. Requires the phpredis
 * extension. Every stream expires after the configured TTL, counted from the last append.
 */
class RedisStreamStorage implements StreamStorageInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'chatflow:stream:',
        private readonly int $ttlSeconds = 86400 * 30,
    ) {}

    /**
     * The id check, the push and the id registration run inside one Lua script, atomically.
     */
    public function append(string $stream, array $data, ?string $id = null): int
    {
        $record = $this->encode(['id' => $id, 'data' => $data]);

        $script = <<<'LUA'
            if ARGV[2] ~= '' then
                local existing = redis.call('HGET', KEYS[2], ARGV[2])
                if existing then return tonumber(existing) end
            end
            local seq = redis.call('RPUSH', KEYS[1], ARGV[1])
            if ARGV[2] ~= '' then
                redis.call('HSET', KEYS[2], ARGV[2], seq)
                redis.call('EXPIRE', KEYS[2], tonumber(ARGV[3]))
            end
            redis.call('EXPIRE', KEYS[1], tonumber(ARGV[3]))
            return seq
            LUA;

        $result = $this->redis->eval(
            $script,
            [$this->listKey($stream), $this->idsKey($stream), $record, $id ?? '', (string) $this->ttlSeconds],
            2,
        );

        if (!is_numeric($result)) {
            throw new StorageException(\sprintf('Failed to append to stream "%s" in Redis.', $stream));
        }

        return (int) $result;
    }

    public function read(string $stream, int $fromSeq = 1, ?int $limit = null): array
    {
        $start = max(0, $fromSeq - 1);
        $stop = $limit === null ? -1 : $start + $limit - 1;

        if ($limit !== null && $limit <= 0) {
            return [];
        }

        $raw = $this->redis->lrange($this->listKey($stream), $start, $stop);
        $records = [];

        foreach (\is_array($raw) ? $raw : [] as $offset => $line) {
            if (!\is_string($line)) {
                continue;
            }

            $record = $this->decode($line, $start + (int) $offset + 1);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function last(string $stream): int
    {
        $length = $this->redis->lLen($this->listKey($stream));

        return \is_int($length) ? $length : 0;
    }

    public function truncate(string $stream): void
    {
        $this->redis->del($this->listKey($stream), $this->idsKey($stream));
    }

    private function listKey(string $stream): string
    {
        return $this->prefix . $stream;
    }

    private function idsKey(string $stream): string
    {
        return $this->prefix . $stream . ':ids';
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode stream record: ' . $e->getMessage(), 0, $e);
        }
    }

    private function decode(string $line, int $seq): ?StreamRecord
    {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_array($decoded['data'] ?? null)) {
            return null;
        }

        $data = [];

        foreach ($decoded['data'] as $key => $value) {
            $data[(string) $key] = $value;
        }

        $id = $decoded['id'] ?? null;

        return new StreamRecord($seq, $data, \is_string($id) ? $id : null);
    }
}
