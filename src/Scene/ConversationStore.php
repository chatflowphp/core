<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use Automata\Clock\SystemClock;
use Automata\Exception\SnapshotHydrationException;
use Automata\Snapshot\SnapshotStoreInterface;
use Automata\Snapshot\StateSnapshot;
use ChatFlow\Exception\ConversationConflictException;
use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\RecordVersion;
use ChatFlow\Storage\StorageInterface;
use ChatFlow\Storage\VersionedStorageInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Persists conversation snapshots through a ChatFlow storage driver and applies the session TTL.
 * Records that cannot be hydrated (older formats, corrupted data) are deleted and treated as a
 * fresh conversation.
 */
final class ConversationStore implements SnapshotStoreInterface
{
    /**
     * The snapshot field that grows with every tick; it doubles as the version of the record.
     */
    private const VERSION_KEY = 'tickCount';

    private readonly ClockInterface $clock;

    /**
     * Version of the record as it was last read or written, per conversation.
     *
     * @var array<string, int|null>
     */
    private array $versions = [];

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
            $this->versions[$key] = null;

            return null;
        }

        try {
            $snapshot = StateSnapshot::fromArray($data);
        } catch (SnapshotHydrationException) {
            $this->delete($key);

            return null;
        }

        if ($this->isExpired($snapshot)) {
            $this->delete($key);

            return null;
        }

        $this->versions[$key] = $snapshot->tickCount;

        return $snapshot;
    }

    /**
     * Writes the snapshot only when the stored one is still the one this request read, so a
     * conversation handled by two workers at once cannot lose the changes of either.
     *
     * @throws ConversationConflictException When another worker wrote the conversation meanwhile
     * @throws StorageException
     */
    public function save(string $key, StateSnapshot $snapshot): void
    {
        $expected = $this->versions[$key] ?? null;
        $record = $snapshot->toArray();

        if ($this->storage instanceof VersionedStorageInterface) {
            if (!$this->storage->saveIfVersion($key, $record, self::VERSION_KEY, $expected)) {
                throw self::conflict($key, $expected);
            }
        } else {
            // Custom drivers without compare-and-swap: the conflict is detected, not prevented.
            if (!RecordVersion::matches($this->storage->get($key), self::VERSION_KEY, $expected)) {
                throw self::conflict($key, $expected);
            }

            $this->storage->save($key, $record);
        }

        $this->versions[$key] = $snapshot->tickCount;
    }

    /**
     * @throws StorageException
     */
    public function delete(string $key): void
    {
        $this->storage->delete($key);
        $this->versions[$key] = null;
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

    private static function conflict(string $key, ?int $expected): ConversationConflictException
    {
        return new ConversationConflictException(\sprintf(
            'Conversation "%s" was changed by another worker (expected version %s).',
            $key,
            $expected === null ? 'none' : (string) $expected,
        ));
    }
}
