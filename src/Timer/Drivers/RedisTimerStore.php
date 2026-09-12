<?php

declare(strict_types=1);

namespace ChatFlow\Timer\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Timer\Timer;
use ChatFlow\Timer\TimerStoreInterface;
use DateTimeImmutable;
use JsonException;
use Redis;

/**
 * Timers in a Redis sorted set scored by their time, with the timer bodies in a hash. Requires
 * the phpredis extension.
 */
class RedisTimerStore implements TimerStoreInterface
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'chatflow:timers',
    ) {}

    public function schedule(Timer $timer): void
    {
        try {
            $json = json_encode($timer->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode timer: ' . $e->getMessage(), 0, $e);
        }

        $this->redis->multi();
        $this->redis->zAdd($this->prefix . ':due', $timer->at->getTimestamp(), $timer->id);
        $this->redis->hSet($this->prefix . ':bodies', $timer->id, $json);
        $this->redis->sAdd($this->prefix . ':conv:' . $timer->conversationId, $timer->id);
        $this->redis->exec();
    }

    public function cancel(string $id): void
    {
        $raw = $this->redis->hGet($this->prefix . ':bodies', $id);
        $timer = \is_string($raw) ? $this->decode($raw) : null;

        $this->redis->multi();
        $this->redis->zRem($this->prefix . ':due', $id);
        $this->redis->hDel($this->prefix . ':bodies', $id);

        if ($timer !== null) {
            $this->redis->srem($this->prefix . ':conv:' . $timer->conversationId, $id);
        }

        $this->redis->exec();
    }

    public function due(DateTimeImmutable $now, int $limit = 100): array
    {
        if ($limit <= 0) {
            return [];
        }

        $ids = $this->redis->zRangeByScore($this->prefix . ':due', '-inf', (string) $now->getTimestamp(), ['limit' => [0, $limit]]);

        return $this->load(\is_array($ids) ? $ids : []);
    }

    public function forConversation(string $conversationId): array
    {
        $ids = $this->redis->sMembers($this->prefix . ':conv:' . $conversationId);
        $timers = $this->load(\is_array($ids) ? $ids : []);
        usort($timers, static fn(Timer $a, Timer $b): int => [$a->at->getTimestamp(), $a->id] <=> [$b->at->getTimestamp(), $b->id]);

        return $timers;
    }

    /**
     * @param array<array-key, mixed> $ids
     *
     * @return list<Timer>
     */
    private function load(array $ids): array
    {
        $timers = [];

        foreach ($ids as $id) {
            if (!\is_string($id)) {
                continue;
            }

            $raw = $this->redis->hGet($this->prefix . ':bodies', $id);
            $timer = \is_string($raw) ? $this->decode($raw) : null;

            if ($timer !== null) {
                $timers[] = $timer;
            }
        }

        return $timers;
    }

    private function decode(string $raw): ?Timer
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($decoded) ? Timer::fromArray($decoded) : null;
    }
}
