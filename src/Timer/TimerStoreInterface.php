<?php

declare(strict_types=1);

namespace ChatFlow\Timer;

use ChatFlow\Exception\StorageException;
use DateTimeImmutable;

/**
 * Keeps timers until they are due. Scheduling an id that exists replaces the timer, so "wake me
 * 24 hours after the last message" is one schedule() per message under a stable id.
 */
interface TimerStoreInterface
{
    /**
     * @throws StorageException
     */
    public function schedule(Timer $timer): void;

    /**
     * @throws StorageException
     */
    public function cancel(string $id): void;

    /**
     * Timers whose time has come, earliest first, at most `$limit` of them. They stay in the store
     * until cancelled; Application::runDue() cancels each one after its tick ran.
     *
     * @return list<Timer>
     *
     * @throws StorageException
     */
    public function due(DateTimeImmutable $now, int $limit = 100): array;

    /**
     * Every timer of a conversation, earliest first.
     *
     * @return list<Timer>
     *
     * @throws StorageException
     */
    public function forConversation(string $conversationId): array;
}
