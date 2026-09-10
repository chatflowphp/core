<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use Automata\Clock\SystemClock;
use Automata\Exception\SnapshotHydrationException;
use Automata\Snapshot\SnapshotStoreInterface;
use Automata\Snapshot\StateSnapshot;
use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StorageInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Persists conversation snapshots through a ChatFlow storage driver and applies the session TTL.
 * Records that cannot be hydrated (older formats, corrupted data) are deleted and treated as a
 * fresh conversation.
 */
final class ConversationStore implements SnapshotStoreInterface
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ?int $ttlSeconds = null,
        ?ClockInterface $clock = null,
    ) {
        if ($this->ttlSeconds !== null && $this->ttlSeconds <= 0) {
            throw new InvalidArgumentException('Session TTL must be a positive number of seconds.');
        }

        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @throws StorageException
     */
    public function load(string $key): ?StateSnapshot
    {
        $data = $this->storage->get($key);

        if ($data === null) {
            return null;
        }

        try {
            $snapshot = StateSnapshot::fromArray($data);
        } catch (SnapshotHydrationException) {
            $this->storage->delete($key);

            return null;
        }

        if ($this->isExpired($snapshot)) {
            $this->storage->delete($key);

            return null;
        }

        return $snapshot;
    }

    /**
     * @throws StorageException
     */
    public function save(string $key, StateSnapshot $snapshot): void
    {
        $this->storage->save($key, $snapshot->toArray());
    }

    /**
     * @throws StorageException
     */
    public function delete(string $key): void
    {
        $this->storage->delete($key);
    }

    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    public function getTtlSeconds(): ?int
    {
        return $this->ttlSeconds;
    }

    private function isExpired(StateSnapshot $snapshot): bool
    {
        if ($this->ttlSeconds === null) {
            return false;
        }

        return $this->clock->now()->getTimestamp() - $snapshot->createdAt->getTimestamp() > $this->ttlSeconds;
    }
}
