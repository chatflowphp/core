<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StreamRecord;
use ChatFlow\Storage\StreamStorageInterface;
use JsonException;
use PDO;
use PDOException;

/**
 * PDO streams compatible with MySQL, PostgreSQL and SQLite. Expects a table with the columns
 * `stream_key`, `seq`, `dedupe_id`, `record_data` and `created_at`; see createTableSql().
 */
class DatabaseStreamStorage implements StreamStorageInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'chatflow_streams',
    ) {}

    /**
     * DDL for the stream table on the given PDO driver name ("mysql", "pgsql" or "sqlite").
     */
    public static function createTableSql(string $driver, string $table = 'chatflow_streams'): string
    {
        return match ($driver) {
            'mysql' => \sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (`stream_key` VARCHAR(191) NOT NULL, `seq` INT NOT NULL, `dedupe_id` VARCHAR(191) NULL, `record_data` LONGTEXT NOT NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`stream_key`, `seq`), UNIQUE KEY `%s_dedupe` (`stream_key`, `dedupe_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                $table,
                $table,
            ),
            'pgsql' => \sprintf(
                'CREATE TABLE IF NOT EXISTS "%s" ("stream_key" VARCHAR(191) NOT NULL, "seq" INTEGER NOT NULL, "dedupe_id" VARCHAR(191) NULL, "record_data" TEXT NOT NULL, "created_at" TIMESTAMP NOT NULL, PRIMARY KEY ("stream_key", "seq"), UNIQUE ("stream_key", "dedupe_id"))',
                $table,
            ),
            default => \sprintf(
                'CREATE TABLE IF NOT EXISTS "%s" ("stream_key" TEXT NOT NULL, "seq" INTEGER NOT NULL, "dedupe_id" TEXT NULL, "record_data" TEXT NOT NULL, "created_at" TEXT NOT NULL, PRIMARY KEY ("stream_key", "seq"), UNIQUE ("stream_key", "dedupe_id"))',
                $table,
            ),
        };
    }

    /**
     * The id lookup, the position and the insert happen inside one transaction; on MySQL and
     * PostgreSQL the last row is locked with `FOR UPDATE`, SQLite uses `BEGIN IMMEDIATE`.
     */
    public function append(string $stream, array $data, ?string $id = null): int
    {
        $json = $this->encode(['id' => $id, 'data' => $data]);
        $now = gmdate('Y-m-d H:i:s');
        $driver = $this->driverName();
        $owns = false;

        try {
            $owns = $this->begin($driver);

            if ($id !== null) {
                $existing = $this->prepare(\sprintf('SELECT seq FROM %s WHERE stream_key = ? AND dedupe_id = ?', $this->table));
                $existing->execute([$stream, $id]);
                $found = $existing->fetchColumn();

                if ($found !== false) {
                    $this->finish($driver, $owns, true);

                    return (int) $found;
                }
            }

            $select = \sprintf('SELECT MAX(seq) FROM %s WHERE stream_key = ?', $this->table)
                . ($driver === 'sqlite' ? '' : ' FOR UPDATE');
            $statement = $this->prepare($select);
            $statement->execute([$stream]);
            $max = $statement->fetchColumn();
            $seq = (is_numeric($max) ? (int) $max : 0) + 1;

            $insert = $this->prepare(\sprintf(
                'INSERT INTO %s (stream_key, seq, dedupe_id, record_data, created_at) VALUES (?, ?, ?, ?, ?)',
                $this->table,
            ));
            $insert->execute([$stream, $seq, $id, $json, $now]);

            $this->finish($driver, $owns, true);

            return $seq;
        } catch (PDOException $e) {
            $this->finish($driver, $owns, false);

            throw new StorageException('Failed to append to stream: ' . $e->getMessage(), 0, $e);
        }
    }

    public function read(string $stream, int $fromSeq = 1, ?int $limit = null): array
    {
        if ($limit !== null && $limit <= 0) {
            return [];
        }

        $sql = \sprintf('SELECT seq, dedupe_id, record_data FROM %s WHERE stream_key = ? AND seq >= ? ORDER BY seq ASC', $this->table);

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $statement = $this->prepare($sql);
        $statement->execute([$stream, $fromSeq]);
        $records = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!\is_array($row)) {
                continue;
            }

            $record = $this->decode($row);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function last(string $stream): int
    {
        $statement = $this->prepare(\sprintf('SELECT MAX(seq) FROM %s WHERE stream_key = ?', $this->table));
        $statement->execute([$stream]);
        $max = $statement->fetchColumn();

        return is_numeric($max) ? (int) $max : 0;
    }

    public function truncate(string $stream): void
    {
        $statement = $this->prepare(\sprintf('DELETE FROM %s WHERE stream_key = ?', $this->table));
        $statement->execute([$stream]);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function decode(array $row): ?StreamRecord
    {
        $seq = $row['seq'] ?? null;
        $raw = $row['record_data'] ?? null;

        if (!is_numeric($seq) || !\is_string($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_array($decoded['data'] ?? null)) {
            return null;
        }

        $data = [];

        foreach ($decoded['data'] as $key => $value) {
            $data[(string) $key] = $value;
        }

        $id = $row['dedupe_id'] ?? null;

        return new StreamRecord((int) $seq, $data, \is_string($id) ? $id : null);
    }

    /**
     * @throws StorageException
     */
    private function prepare(string $sql): \PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
        } catch (PDOException $e) {
            throw new StorageException('Failed to prepare statement: ' . $e->getMessage(), 0, $e);
        }

        if ($statement === false) {
            throw new StorageException('Failed to prepare statement.');
        }

        return $statement;
    }

    private function driverName(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return \is_string($driver) ? $driver : '';
    }

    private function begin(string $driver): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }

        if ($driver === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');

            return true;
        }

        $this->pdo->beginTransaction();

        return true;
    }

    private function finish(string $driver, bool $owns, bool $commit): void
    {
        if (!$owns) {
            return;
        }

        if ($driver === 'sqlite') {
            $this->pdo->exec($commit ? 'COMMIT' : 'ROLLBACK');

            return;
        }

        if ($commit) {
            $this->pdo->commit();

            return;
        }

        $this->pdo->rollBack();
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode stream record: ' . $e->getMessage(), 0, $e);
        }
    }
}
