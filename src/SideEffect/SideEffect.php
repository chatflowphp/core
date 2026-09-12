<?php

declare(strict_types=1);

namespace ChatFlow\SideEffect;

use ChatFlow\Support\SerializableValueValidator;

/**
 * Work that belongs to a tick but must not run inside it: a refund, a CRM record, an email, a
 * call to a slow API. It is stored with the conversation snapshot and executed after the snapshot
 * is committed, so a rolled-back tick never triggers it and a crash never loses it.
 *
 * The id is the idempotency key: the runtime may execute the same effect more than once when a
 * crash or a concurrent write hits between the execution and the record of it. Handlers are
 * expected to be idempotent by id.
 */
final class SideEffect
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $handler,
        public readonly array $payload = [],
        public readonly int $attempts = 0,
        public readonly ?string $error = null,
    ) {}

    public function withFailedAttempt(string $error): self
    {
        return new self($this->id, $this->handler, $this->payload, $this->attempts + 1, $error);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'handler' => $this->handler,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'error' => $this->error,
        ];
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $id = $raw['id'] ?? null;
        $handler = $raw['handler'] ?? null;
        $payload = $raw['payload'] ?? [];
        $attempts = $raw['attempts'] ?? 0;
        $error = $raw['error'] ?? null;

        if (!\is_string($id) || $id === '' || !\is_string($handler) || $handler === '' || !\is_array($payload) || !\is_int($attempts)) {
            return null;
        }

        return new self(
            $id,
            $handler,
            SerializableValueValidator::normalizeMap($payload, 'payload'),
            $attempts,
            \is_string($error) ? $error : null,
        );
    }
}
