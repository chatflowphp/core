<?php

declare(strict_types=1);

namespace ChatFlow\Timer\Drivers;

use ChatFlow\Timer\Timer;
use ChatFlow\Timer\TimerStoreInterface;
use DateTimeImmutable;

/**
 * In-process timers for tests and single-process bots.
 */
class MemoryTimerStore implements TimerStoreInterface
{
    /**
     * @var array<string, Timer>
     */
    private array $timers = [];

    public function schedule(Timer $timer): void
    {
        $this->timers[$timer->id] = $timer;
    }

    public function cancel(string $id): void
    {
        unset($this->timers[$id]);
    }

    public function due(DateTimeImmutable $now, int $limit = 100): array
    {
        $due = array_values(array_filter($this->timers, static fn(Timer $timer): bool => $timer->at <= $now));
        usort($due, self::byTime(...));

        return \array_slice($due, 0, max(0, $limit));
    }

    public function forConversation(string $conversationId): array
    {
        $timers = array_values(array_filter($this->timers, static fn(Timer $timer): bool => $timer->conversationId === $conversationId));
        usort($timers, self::byTime(...));

        return $timers;
    }

    /**
     * @return list<Timer>
     */
    public function all(): array
    {
        $timers = array_values($this->timers);
        usort($timers, self::byTime(...));

        return $timers;
    }

    private static function byTime(Timer $a, Timer $b): int
    {
        return [$a->at->getTimestamp(), $a->id] <=> [$b->at->getTimestamp(), $b->id];
    }
}
