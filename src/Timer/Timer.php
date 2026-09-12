<?php

declare(strict_types=1);

namespace ChatFlow\Timer;

use ChatFlow\Support\SerializableValueValidator;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A request to wake a conversation at a point in time: a reminder, a follow-up after silence, a
 * deadline. Stored in a TimerStoreInterface and turned into a system tick by
 * Application::runDue().
 */
final class Timer
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $conversationId,
        public readonly DateTimeImmutable $at,
        public readonly string $reason,
        public readonly array $payload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'conversation' => $this->conversationId,
            'at' => $this->at->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            'reason' => $this->reason,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $id = $raw['id'] ?? null;
        $conversation = $raw['conversation'] ?? null;
        $at = $raw['at'] ?? null;
        $reason = $raw['reason'] ?? null;
        $payload = $raw['payload'] ?? [];

        if (!\is_string($id) || $id === '' || !\is_string($conversation) || $conversation === '' || !\is_string($at) || !\is_string($reason) || !\is_array($payload)) {
            return null;
        }

        $time = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $at);

        if ($time === false) {
            return null;
        }

        return new self($id, $conversation, $time, $reason, SerializableValueValidator::normalizeMap($payload, 'payload'));
    }
}
