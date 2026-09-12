<?php

declare(strict_types=1);

namespace ChatFlow\Timer\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Timer\Timer;
use ChatFlow\Timer\TimerStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOException;

/**
 * PDO timers compatible with MySQL, PostgreSQL and SQLite. Expects a table with the columns
 * `timer_id` (primary key), `conversation_id`, `due_at` and `timer_data`; see createTableSql().
 */
class DatabaseTimerStore implements TimerStoreInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'chatflow_timers',
    ) {}

    /**
     * DDL for the timer table on the given PDO driver name ("mysql", "pgsql" or "sqlite").
     */
    public static function createTableSql(string $driver, string $table = 'chatflow_timers'): string
    {
        return match ($driver) {
            'mysql' => \sprintf(
                'CREATE TABLE IF NOT EXISTS `%s` (`timer_id` VARCHAR(191) NOT NULL PRIMARY KEY, `conversation_id` VARCHAR(191) NOT NULL, `due_at` DATETIME NOT NULL, `timer_data` LONGTEXT NOT NULL, KEY `%s_due` (`due_at`), KEY `%s_conversation` (`conversation_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                $table,
                $table,
                $table,
            ),
            'pgsql' => \sprintf(
                'CREATE TABLE IF NOT EXISTS "%s" ("timer_id" VARCHAR(191) NOT NULL PRIMARY KEY, "conversation_id" VARCHAR(191) NOT NULL, "due_at" TIMESTAMP NOT NULL, "timer_data" TEXT NOT NULL); CREATE INDEX IF NOT EXISTS "%s_due" ON "%s" ("due_at"); CREATE INDEX IF NOT EXISTS "%s_conversation" ON "%s" ("conversation_id")',
                $table,
                $table,
                $table,
                $table,
                $table,
            ),
            default => \sprintf(
                'CREATE TABLE IF NOT EXISTS "%s" ("timer_id" TEXT NOT NULL PRIMARY KEY, "conversation_id" TEXT NOT NULL, "due_at" TEXT NOT NULL, "timer_data" TEXT NOT NULL); CREATE INDEX IF NOT EXISTS "%s_due" ON "%s" ("due_at"); CREATE INDEX IF NOT EXISTS "%s_conversation" ON "%s" ("conversation_id")',
                $table,
                $table,
                $table,
                $table,
                $table,
            ),
        };
    }

    public function schedule(Timer $timer): void
    {
        try {
            $json = json_encode($timer->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode timer: ' . $e->getMessage(), 0, $e);
        }

        $dueAt = $timer->at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        try {
            $update = $this->prepare(\sprintf('UPDATE %s SET conversation_id = ?, due_at = ?, timer_data = ? WHERE timer_id = ?', $this->table));
            $update->execute([$timer->conversationId, $dueAt, $json, $timer->id]);

            if ($update->rowCount() > 0) {
                return;
            }

            try {
                $insert = $this->prepare(\sprintf('INSERT INTO %s (timer_id, conversation_id, due_at, timer_data) VALUES (?, ?, ?, ?)', $this->table));
                $insert->execute([$timer->id, $timer->conversationId, $dueAt, $json]);
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000' && (string) $e->getCode() !== '23505') {
                    throw $e;
                }

                $update->execute([$timer->conversationId, $dueAt, $json, $timer->id]);
            }
        } catch (PDOException $e) {
            throw new StorageException('Failed to save timer: ' . $e->getMessage(), 0, $e);
        }
    }

    public function cancel(string $id): void
    {
        $statement = $this->prepare(\sprintf('DELETE FROM %s WHERE timer_id = ?', $this->table));
        $statement->execute([$id]);
    }

    public function due(DateTimeImmutable $now, int $limit = 100): array
    {
        if ($limit <= 0) {
            return [];
        }

        $statement = $this->prepare(\sprintf('SELECT timer_data FROM %s WHERE due_at <= ? ORDER BY due_at ASC, timer_id ASC LIMIT %d', $this->table, $limit));
        $statement->execute([$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);

        return $this->collect($statement);
    }

    public function forConversation(string $conversationId): array
    {
        $statement = $this->prepare(\sprintf('SELECT timer_data FROM %s WHERE conversation_id = ? ORDER BY due_at ASC, timer_id ASC', $this->table));
        $statement->execute([$conversationId]);

        return $this->collect($statement);
    }

    /**
     * @return list<Timer>
     */
    private function collect(\PDOStatement $statement): array
    {
        $timers = [];

        while (($raw = $statement->fetchColumn()) !== false) {
            if (!\is_string($raw)) {
                continue;
            }

            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $timer = \is_array($decoded) ? Timer::fromArray($decoded) : null;

            if ($timer !== null) {
                $timers[] = $timer;
            }
        }

        return $timers;
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
