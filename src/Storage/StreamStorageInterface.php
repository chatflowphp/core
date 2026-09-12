<?php

declare(strict_types=1);

namespace ChatFlow\Storage;

use ChatFlow\Exception\StorageException;

/**
 * Append-only streams of records: message history, audit trails, event journals. A stream is
 * addressed by a key; records get monotonically increasing positions starting at 1 and are read
 * back in order, from any position.
 *
 * Streams are separate from `StorageInterface` records: a conversation snapshot is one record
 * that is rewritten on every tick, a stream only grows. Keep the two under distinct keys, for
 * example `<conversation id>:messages` for a stream.
 */
interface StreamStorageInterface
{
    /**
     * Appends a record and returns its position. With a deduplication id, appending the same id
     * twice writes nothing the second time and returns the position of the first write, which
     * makes retried webhooks and replayed jobs safe.
     *
     * @param array<string, mixed> $data
     *
     * @throws StorageException
     */
    public function append(string $stream, array $data, ?string $id = null): int;

    /**
     * Reads records in order, starting at `$fromSeq` (inclusive), at most `$limit` of them.
     *
     * @return list<StreamRecord>
     *
     * @throws StorageException
     */
    public function read(string $stream, int $fromSeq = 1, ?int $limit = null): array;

    /**
     * Position of the last record, or 0 for an empty or missing stream.
     *
     * @throws StorageException
     */
    public function last(string $stream): int;

    /**
     * Removes the stream and everything in it.
     *
     * @throws StorageException
     */
    public function truncate(string $stream): void;
}
