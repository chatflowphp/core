<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Storage\RecordVersion;
use ChatFlow\Storage\VersionedStorageInterface;

/**
 * In-process storage for tests and single-process bots. Data is lost when the script ends.
 */
class MemoryStorage implements VersionedStorageInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $records = [];

    public function get(string $key): ?array
    {
        return $this->records[$key] ?? null;
    }

    public function save(string $key, array $data): void
    {
        $this->records[$key] = $data;
    }

    public function saveIfVersion(string $key, array $data, string $versionKey, ?int $expectedVersion): bool
    {
        if (!RecordVersion::matches($this->records[$key] ?? null, $versionKey, $expectedVersion)) {
            return false;
        }

        $this->records[$key] = $data;

        return true;
    }

    public function delete(string $key): void
    {
        unset($this->records[$key]);
    }

    public function exists(string $key): bool
    {
        return isset($this->records[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->records);
    }

    public function clearAll(): void
    {
        $this->records = [];
    }
}
