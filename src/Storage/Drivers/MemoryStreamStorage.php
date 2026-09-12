<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Storage\StreamRecord;
use ChatFlow\Storage\StreamStorageInterface;

/**
 * In-process streams for tests and single-process bots. Data is lost when the script ends.
 */
class MemoryStreamStorage implements StreamStorageInterface
{
    /**
     * @var array<string, list<StreamRecord>>
     */
    private array $streams = [];

    /**
     * @var array<string, array<string, int>>
     */
    private array $ids = [];

    public function append(string $stream, array $data, ?string $id = null): int
    {
        if ($id !== null && isset($this->ids[$stream][$id])) {
            return $this->ids[$stream][$id];
        }

        $seq = \count($this->streams[$stream] ?? []) + 1;
        $this->streams[$stream][] = new StreamRecord($seq, $data, $id);

        if ($id !== null) {
            $this->ids[$stream][$id] = $seq;
        }

        return $seq;
    }

    public function read(string $stream, int $fromSeq = 1, ?int $limit = null): array
    {
        $records = $this->streams[$stream] ?? [];
        $offset = max(0, $fromSeq - 1);

        return \array_slice($records, $offset, $limit);
    }

    public function last(string $stream): int
    {
        return \count($this->streams[$stream] ?? []);
    }

    public function truncate(string $stream): void
    {
        unset($this->streams[$stream], $this->ids[$stream]);
    }

    /**
     * @return list<string>
     */
    public function streams(): array
    {
        return array_keys($this->streams);
    }

    public function clearAll(): void
    {
        $this->streams = [];
        $this->ids = [];
    }
}
