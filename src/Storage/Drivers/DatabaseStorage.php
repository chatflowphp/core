<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\RecordVersion;
use ChatFlow\Storage\VersionedStorageInterface;
use JsonException;
use PDO;
use PDOException;

/**
 * PDO storage compatible with MySQL, PostgreSQL and SQLite. Expects a table with the columns
 * `record_key` (primary key), `record_data` (text or json) and `updated_at`; see createTableSql().
 */
class DatabaseStorage implements VersionedStorageInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'chatflow_conversations',
    ) {}

    /**
     * DDL for the storage table on the given PDO driver name ("mysql", "pgsql" or "sqlite").
     */
    public static function createTableSql(string $driver, string $table = 'chatflow_conversations'): string
    {
        return match ($driver) {
            'mysql' => \sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (`record_key` VARCHAR(191) NOT NULL PRIMARY KEY, `record_data` LONGTEXT NOT NULL, `updated_at` DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                $table,
            ),
            'pgsql' => \sprintf(
                'CREATE TABLE IF NOT EXISTS "%s" ("record_key" VARCHAR(191) NOT NULL PRIMARY KEY, "record_data" TEXT NOT NULL, "updated_at" TIMESTAMP NOT NULL)',
                $table,
            ),
            default => \sprintf(
                'CREATE TABLE IF NOT EXISTS "%s" ("record_key" TEXT NOT NULL PRIMARY KEY, "record_data" TEXT NOT NULL, "updated_at" TEXT NOT NULL)',
                $table,
            ),
        };
    }

    /**
     * The row is read and written inside one transaction, locked against concurrent writers
     * (`FOR UPDATE` on MySQL and PostgreSQL, `BEGIN IMMEDIATE` on SQLite).
     */
    public function saveIfVersion(string $key, array $data, string $versionKey, ?int $expectedVersion): bool
    {
        $json = $this->encode($data);
        $now = gmdate('Y-m-d H:i:s');
        $driver = $this->driverName();
        $owns = false;

        try {
            $owns = $this->begin($driver);

            $select = \sprintf('SELECT record_data FROM %s WHERE record_key = ?', $this->table)
                . ($driver === 'sqlite' ? '' : ' FOR UPDATE');
            $statement = $this->prepare($select);
            $statement->execute([$key]);
            $raw = $statement->fetchColumn();
            $current = \is_string($raw) ? self::decode($raw) : null;

            if (!RecordVersion::matches($current, $versionKey, $expectedVersion)) {
                $this->finish($driver, $owns, false);

                return false;
            }

            if ($current === null) {
                $insert = $this->prepare(\sprintf('INSERT INTO %s (record_key, record_data, updated_at) VALUES (?, ?, ?)', $this->table));
                $insert->execute([$key, $json, $now]);
            } else {
                $update = $this->prepare(\sprintf('UPDATE %s SET record_data = ?, updated_at = ? WHERE record_key = ?', $this->table));
                $update->execute([$json, $now, $key]);
            }

            $this->finish($driver, $owns, true);

            return true;
        } catch (PDOException $e) {
            $this->finish($driver, $owns, false);

            throw new StorageException('Failed to save record: ' . $e->getMessage(), 0, $e);
        }
    }

    public function get(string $key): ?array
    {
        $statement = $this->prepare(\sprintf('SELECT record_data FROM %s WHERE record_key = ? LIMIT 1', $this->table));
        $statement->execute([$key]);
        $raw = $statement->fetchColumn();

        if (!\is_string($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $record = [];

        foreach ($decoded as $recordKey => $value) {
            $record[(string) $recordKey] = $value;
        }

        return $record;
    }

    public function save(string $key, array $data): void
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode record: ' . $e->getMessage(), 0, $e);
        }

        $now = gmdate('Y-m-d H:i:s');

        try {
            $update = $this->prepare(\sprintf('UPDATE %s SET record_data = ?, updated_at = ? WHERE record_key = ?', $this->table));
            $update->execute([$json, $now, $key]);

            if ($update->rowCount() > 0) {
                return;
            }

            try {
                $insert = $this->prepare(\sprintf('INSERT INTO %s (record_key, record_data, updated_at) VALUES (?, ?, ?)', $this->table));
                $insert->execute([$key, $json, $now]);
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000' && (string) $e->getCode() !== '23505') {
                    throw $e;
                }

                // Lost the race against a concurrent insert: the row exists now, update it.
                $update->execute([$json, $now, $key]);
            }
        } catch (PDOException $e) {
            throw new StorageException('Failed to save record: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $key): void
    {
        $statement = $this->prepare(\sprintf('DELETE FROM %s WHERE record_key = ?', $this->table));
        $statement->execute([$key]);
    }

    public function exists(string $key): bool
    {
        $statement = $this->prepare(\sprintf('SELECT 1 FROM %s WHERE record_key = ? LIMIT 1', $this->table));
        $statement->execute([$key]);

        return $statement->fetchColumn() !== false;
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

    /**
     * @return bool Whether this call started the transaction and therefore has to end it
     */
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
     * @param array<string, mixed> $data
     *
     * @throws StorageException
     */
    private function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode record: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $record = [];

        foreach ($decoded as $key => $value) {
            $record[(string) $key] = $value;
        }

        return $record;
    }
}
