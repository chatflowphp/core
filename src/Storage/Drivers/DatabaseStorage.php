<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StorageInterface;
use JsonException;
use PDO;
use PDOException;

/**
 * PDO storage compatible with MySQL, PostgreSQL and SQLite. Expects a table with the columns
 * `record_key` (primary key), `record_data` (text or json) and `updated_at`; see createTableSql().
 */
class DatabaseStorage implements StorageInterface
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
}
