<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StorageInterface;

/**
 * File-based storage driver with atomic writes and file locking
 * Stores session data as JSON files in storage/sessions/ directory.
 */
class FileStorage implements StorageInterface
{
    private const LOCK_TIMEOUT = 30;

    public function __construct(private readonly string $storagePath)
    {
        $this->ensureDirectoryExists();
    }

    private function ensureDirectoryExists(): void
    {
        $sessionsDir = $this->storagePath . '/sessions';
        if (!is_dir($sessionsDir)) {
            mkdir($sessionsDir, 0755, true);
        }

        $locksDir = $this->storagePath . '/locks';
        if (!is_dir($locksDir)) {
            mkdir($locksDir, 0755, true);
        }
    }

    private function getFilePath(string $conversationId): string
    {
        $safeConversationId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $conversationId) ?? $conversationId;

        return $this->storagePath . '/sessions/' . $safeConversationId . '.json';
    }

    private function getLockFilePath(string $conversationId): string
    {
        $safeConversationId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $conversationId) ?? $conversationId;

        return $this->storagePath . '/locks/' . $safeConversationId . '.lock';
    }

    /**
     * Acquire exclusive lock for the given chat ID.
     *
     * @return resource
     *
     * @throws StorageException If lock file creation fails or timeout occurs
     */
    private function acquireLock(string $conversationId)
    {
        $lockFilePath = $this->getLockFilePath($conversationId);
        $lockFile = fopen($lockFilePath, 'w+');

        if ($lockFile === false) {
            throw new StorageException("Failed to create lock file for conversation {$conversationId}");
        }

        $startTime = time();
        while (!flock($lockFile, LOCK_EX | LOCK_NB)) {
            if (time() - $startTime >= self::LOCK_TIMEOUT) {
                fclose($lockFile);
                throw new StorageException("Failed to acquire lock for conversation {$conversationId} within " . self::LOCK_TIMEOUT . ' seconds');
            }
            usleep(100000);
        }

        return $lockFile;
    }

    /** @param resource $lockFile */
    private function releaseLock($lockFile): void
    {
        if (is_resource($lockFile)) {
            flock($lockFile, LOCK_UN);
            fclose($lockFile);
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws StorageException If file I/O or JSON operation fails
     */
    public function get(string $conversationId): ?array
    {
        $lockFile = $this->acquireLock($conversationId);

        try {
            $filePath = $this->getFilePath($conversationId);

            if (!file_exists($filePath)) {
                return null;
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                return null;
            }

            /** @var mixed $data */
            $data = json_decode($content, true);

            if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            /** @var array<string, mixed> $result */
            $result = $data;

            return $result;
        } finally {
            $this->releaseLock($lockFile);
        }
    }

    /**
     * Save session data atomically.
     *
     * Strategy:
     * 1. Acquire Lock.
     * 2. Write to a temporary file (.tmp).
     * 3. Force flush to disk (fsync).
     * 4. Rename .tmp to actual file (atomic operation on POSIX filesystems).
     * 5. Release Lock.
     *
     * @param array<string, mixed> $data
     *
     * @throws StorageException If writing or renaming fails
     */
    public function save(string $conversationId, array $data): void
    {
        $lockFile = $this->acquireLock($conversationId);

        try {
            $filePath = $this->getFilePath($conversationId);
            $tempFilePath = $filePath . '.tmp';

            $normalizedData = $this->normalizeDataFormat($data);

            $content = json_encode($normalizedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if ($content === false) {
                throw new StorageException('Failed to encode session data');
            }

            $result = file_put_contents($tempFilePath, $content, LOCK_EX);
            if ($result === false) {
                throw new StorageException('Failed to write temporary session data');
            }

            if (function_exists('fsync')) {
                $tempHandle = fopen($tempFilePath, 'r');
                if ($tempHandle !== false) {
                    fsync($tempHandle);
                    fclose($tempHandle);
                }
            }

            if (!rename($tempFilePath, $filePath)) {
                @unlink($tempFilePath);
                throw new StorageException('Failed to rename temporary file to session file');
            }
        } finally {
            $this->releaseLock($lockFile);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeDataFormat(array $data): array
    {
        if (isset($data['meta']) && isset($data['data'])) {
            return $data;
        }

        $conversationId = $data['conversation_id'] ?? ($data['chat_id'] ?? '');

        return [
            'meta' => [
                'conversation_id' => $conversationId,
                'current_scene' => $data['current_scene'] ?? null,
                'updated_at' => time(),
            ],
            'data' => $data,
        ];
    }

    public function delete(string $conversationId): void
    {
        $lockFile = $this->acquireLock($conversationId);

        try {
            $filePath = $this->getFilePath($conversationId);

            if (file_exists($filePath)) {
                unlink($filePath);
            }
        } finally {
            $this->releaseLock($lockFile);
        }
    }

    public function exists(string $conversationId): bool
    {
        $filePath = $this->getFilePath($conversationId);

        return file_exists($filePath);
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }
}
