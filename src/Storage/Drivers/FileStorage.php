<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\RecordVersion;
use ChatFlow\Storage\VersionedStorageInterface;
use JsonException;

/**
 * One JSON file per key with an exclusive lock per key and atomic writes (temporary file, fsync,
 * rename). Suitable for local development and small single-host bots.
 */
class FileStorage implements VersionedStorageInterface
{
    private const LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(private readonly string $storagePath)
    {
        $this->ensureDirectory($this->storagePath . '/records');
        $this->ensureDirectory($this->storagePath . '/locks');
    }

    public function get(string $key): ?array
    {
        $lock = $this->acquireLock($key);

        try {
            return $this->readRecord($key);
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function save(string $key, array $data): void
    {
        $lock = $this->acquireLock($key);

        try {
            $this->writeRecord($key, $data);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Reading and writing happen under the same exclusive lock, so the check and the write cannot
     * be separated by another process.
     */
    public function saveIfVersion(string $key, array $data, string $versionKey, ?int $expectedVersion): bool
    {
        $lock = $this->acquireLock($key);

        try {
            if (!RecordVersion::matches($this->readRecord($key), $versionKey, $expectedVersion)) {
                return false;
            }

            $this->writeRecord($key, $data);

            return true;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws StorageException
     */
    private function readRecord(string $key): ?array
    {
        $path = $this->recordPath($key);

        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new StorageException(\sprintf('Failed to read record "%s".', $key));
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($decoded) ? self::stringKeys($decoded) : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws StorageException
     */
    private function writeRecord(string $key, array $data): void
    {
        $path = $this->recordPath($key);
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            $content = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode record: ' . $e->getMessage(), 0, $e);
        }

        $handle = fopen($temporary, 'w');

        if ($handle === false) {
            throw new StorageException(\sprintf('Failed to create temporary file for record "%s".', $key));
        }

        $written = fwrite($handle, $content);
        fflush($handle);
        fsync($handle);
        fclose($handle);

        if ($written === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new StorageException(\sprintf('Failed to write record "%s".', $key));
        }
    }

    public function delete(string $key): void
    {
        $lock = $this->acquireLock($key);

        try {
            $path = $this->recordPath($key);

            if (is_file($path)) {
                unlink($path);
            }
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->recordPath($key));
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    private function recordPath(string $key): string
    {
        return $this->storagePath . '/records/' . self::safeName($key) . '.json';
    }

    /**
     * @return resource
     *
     * @throws StorageException
     */
    private function acquireLock(string $key)
    {
        $lockPath = $this->storagePath . '/locks/' . self::safeName($key) . '.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new StorageException(\sprintf('Failed to open lock file for record "%s".', $key));
        }

        $startedAt = time();

        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (time() - $startedAt >= self::LOCK_TIMEOUT_SECONDS) {
                fclose($lock);

                throw new StorageException(\sprintf(
                    'Failed to acquire lock for record "%s" within %d seconds.',
                    $key,
                    self::LOCK_TIMEOUT_SECONDS,
                ));
            }

            usleep(50_000);
        }

        return $lock;
    }

    /**
     * @param resource $lock
     */
    private function releaseLock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new StorageException(\sprintf('Failed to create storage directory "%s".', $directory));
        }
    }

    private static function safeName(string $key): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $key);

        return $safe . '_' . substr(hash('sha256', $key), 0, 12);
    }

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $decoded): array
    {
        $record = [];

        foreach ($decoded as $key => $value) {
            $record[(string) $key] = $value;
        }

        return $record;
    }
}
