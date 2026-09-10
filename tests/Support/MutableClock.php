<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class MutableClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $time = '2026-09-10 12:00:00')
    {
        $this->now = new DateTimeImmutable($time);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(\sprintf('+%d seconds', $seconds));
    }
}
