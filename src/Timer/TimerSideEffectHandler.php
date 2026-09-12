<?php

declare(strict_types=1);

namespace ChatFlow\Timer;

use ChatFlow\Exception\SideEffectException;
use ChatFlow\SideEffect\SideEffect;
use ChatFlow\SideEffect\SideEffectHandlerInterface;

/**
 * Writes timers requested with Context::wakeAt() and Context::cancelTimer() into the store. Both
 * requests travel as side effects, so a rolled-back tick schedules nothing and a crash after the
 * commit still schedules the timer.
 */
final class TimerSideEffectHandler implements SideEffectHandlerInterface
{
    public const SCHEDULE = 'chatflow.timer.schedule';
    public const CANCEL = 'chatflow.timer.cancel';

    public function __construct(private readonly TimerStoreInterface $timers) {}

    public function handle(SideEffect $effect): ?array
    {
        if ($effect->handler === self::CANCEL) {
            $id = $effect->payload['id'] ?? null;

            if (!\is_string($id)) {
                throw new SideEffectException('Timer cancellation needs a timer id.');
            }

            $this->timers->cancel($id);

            return null;
        }

        $timer = Timer::fromArray($effect->payload);

        if ($timer === null) {
            throw new SideEffectException(\sprintf('Side effect "%s" does not carry a valid timer.', $effect->id));
        }

        $this->timers->schedule($timer);

        return null;
    }
}
