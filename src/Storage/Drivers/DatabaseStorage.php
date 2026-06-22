<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StorageInterface;
use JsonException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Database storage driver using PDO.
 * Compatible with MySQL, PostgreSQL, and SQLite.
 */
class DatabaseStorage implements StorageInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'chatflow_sessions'
    ) {
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws StorageException If JSON decoding fails
     */
    public function get(string $conversationId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT session_data FROM {$this->table} WHERE conversation_id = ? LIMIT 1");
        if (!$stmt instanceof PDOStatement) {
            return null;
        }

        $stmt->execute([$conversationId]);
        /** @var string|false $result */
        $result = $stmt->fetchColumn();

        if ($result === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

            return $data;
        } catch (JsonException $e) {
            return null;
        }
    }

    /**
     * Save session data using a generic UPSERT approach.
     *
     * Logic:
     * 1. Try UPDATE.
     * 2. If row count is 0, try INSERT.
     * 3. Catch unique constraint violation (race condition) and retry UPDATE.
     *
     * This allows compatibility with MySQL, PostgreSQL, and SQLite without specific SQL dialects.
     *
     * @param array<string, mixed> $data
     *
     * @throws StorageException If database error occurs
     */
    public function save(string $conversationId, array $data): void
    {
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode session data for Database: ' . $e->getMessage(), 0, $e);
        }

        $updateSql = "UPDATE {$this->table} SET session_data = ?, updated_at = NOW() WHERE conversation_id = ?";
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $updateSql = "UPDATE {$this->table} SET session_data = ?, updated_at = datetime('now') WHERE conversation_id = ?";
        }

        $updateStmt = $this->pdo->prepare($updateSql);
        if (!$updateStmt instanceof PDOStatement) {
            throw new StorageException('Failed to prepare update statement');
        }

        $updateStmt->execute([$json, $conversationId]);

        if ($updateStmt->rowCount() > 0) {
            return;
        }

        try {
            $insertSql = "INSERT INTO {$this->table} (conversation_id, session_data, updated_at) VALUES (?, ?, NOW())";
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $insertSql = "INSERT INTO {$this->table} (conversation_id, session_data, updated_at) VALUES (?, ?, datetime('now'))";
            }

            $insertStmt = $this->pdo->prepare($insertSql);
            if (!$insertStmt instanceof PDOStatement) {
                throw new StorageException('Failed to prepare insert statement');
            }

            $insertStmt->execute([$conversationId, $json]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $updateStmt->execute([$json, $conversationId]);
            } else {
                throw new StorageException('Failed to insert session data: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    public function delete(string $conversationId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE conversation_id = ?");
        if (!$stmt instanceof PDOStatement) {
            return;
        }

        $stmt->execute([$conversationId]);
    }

    public function exists(string $conversationId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE conversation_id = ?");
        if (!$stmt instanceof PDOStatement) {
            return false;
        }

        $stmt->execute([$conversationId]);

        return (bool) $stmt->fetchColumn();
    }
}
