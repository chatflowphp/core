<?php

declare(strict_types=1);

namespace ChatFlow\Storage\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StreamRecord;
use ChatFlow\Storage\StreamStorageInterface;
use JsonException;

/**
 * One JSON-lines file per stream plus a small index with the last position and the known
 * deduplication ids, both guarded by an exclusive lock per stream. Suitable for local
 * development and small single-host bots; reading a range scans the file from the start.
 */
class FileStreamStorage implements StreamStorageInterface
{
    private const LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(private readonly string $storagePath)
    {
        $this->ensureDirectory($this->storagePath . '/streams');
        $this->ensureDirectory($this->storagePath . '/locks');
    }

    public function append(string $stream, array $data, ?string $id = null): int
    {
        $lock = $this->acquireLock($stream);

        try {
            $index = $this->readIndex($stream);

            if ($id !== null && isset($index['ids'][$id])) {
                return $index['ids'][$id];
            }

            $seq = $index['last'] + 1;
            $line = $this->encode(['seq' => $seq, 'id' => $id, 'data' => $data]) . "\n";

            if (file_put_contents($this->dataPath($stream), $line, FILE_APPEND) === false) {
                throw new StorageException(\sprintf('Failed to append to stream "%s".', $stream));
            }

            $index['last'] = $seq;

            if ($id !== null) {
                $index['ids'][$id] = $seq;
            }

            $this->writeIndex($stream, $index);

            return $seq;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function read(string $stream, int $fromSeq = 1, ?int $limit = null): array
    {
        $lock = $this->acquireLock($stream);

        try {
            $path = $this->dataPath($stream);

            if (!is_file($path)) {
                return [];
            }

            $handle = fopen($path, 'r');

            if ($handle === false) {
                throw new StorageException(\sprintf('Failed to read stream "%s".', $stream));
            }

            $records = [];

            try {
                while (($line = fgets($handle)) !== false) {
                    if ($limit !== null && \count($records) >= $limit) {
                        break;
                    }

                    $record = $this->decodeLine($line);

                    if ($record === null || $record->seq < $fromSeq) {
                        continue;
                    }

                    $records[] = $record;
                }
            } finally {
                fclose($handle);
            }

            return $records;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function last(string $stream): int
    {
        $lock = $this->acquireLock($stream);

        try {
            return $this->readIndex($stream)['last'];
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function truncate(string $stream): void
    {
        $lock = $this->acquireLock($stream);

        try {
            foreach ([$this->dataPath($stream), $this->indexPath($stream)] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    /**
     * @return array{last: int, ids: array<string, int>}
     */
    private function readIndex(string $stream): array
    {
        $path = $this->indexPath($stream);
        $empty = ['last' => 0, 'ids' => []];

        if (!is_file($path)) {
            return $empty;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new StorageException(\sprintf('Failed to read the index of stream "%s".', $stream));
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $empty;
        }

        if (!\is_array($decoded)) {
            return $empty;
        }

        $last = $decoded['last'] ?? 0;
        $ids = [];

        foreach (\is_array($decoded['ids'] ?? null) ? $decoded['ids'] : [] as $id => $seq) {
            if (\is_int($seq)) {
                $ids[(string) $id] = $seq;
            }
        }

        return ['last' => \is_int($last) ? $last : 0, 'ids' => $ids];
    }

    /**
     * @param array{last: int, ids: array<string, int>} $index
     */
    private function writeIndex(string $stream, array $index): void
    {
        $path = $this->indexPath($stream);
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $handle = fopen($temporary, 'w');

        if ($handle === false) {
            throw new StorageException(\sprintf('Failed to write the index of stream "%s".', $stream));
        }

        $written = fwrite($handle, $this->encode($index));
        fflush($handle);
        fsync($handle);
        fclose($handle);

        if ($written === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new StorageException(\sprintf('Failed to write the index of stream "%s".', $stream));
        }
    }

    private function decodeLine(string $line): ?StreamRecord
    {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_int($decoded['seq'] ?? null) || !\is_array($decoded['data'] ?? null)) {
            return null;
        }

        $id = $decoded['id'] ?? null;
        $data = [];

        foreach ($decoded['data'] as $key => $value) {
            $data[(string) $key] = $value;
        }

        return new StreamRecord($decoded['seq'], $data, \is_string($id) ? $id : null);
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

    private function dataPath(string $stream): string
    {
        return $this->storagePath . '/streams/' . self::safeName($stream) . '.jsonl';
    }

    private function indexPath(string $stream): string
    {
        return $this->storagePath . '/streams/' . self::safeName($stream) . '.index.json';
    }

    /**
     * @return resource
     */
    private function acquireLock(string $stream)
    {
        $lockPath = $this->storagePath . '/locks/stream_' . self::safeName($stream) . '.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new StorageException(\sprintf('Failed to open lock file for stream "%s".', $stream));
        }

        $startedAt = time();

        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (time() - $startedAt >= self::LOCK_TIMEOUT_SECONDS) {
                fclose($lock);

                throw new StorageException(\sprintf(
                    'Failed to acquire lock for stream "%s" within %d seconds.',
                    $stream,
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
}
