<?php

declare(strict_types=1);

namespace ChatFlow\Timer\Drivers;

use ChatFlow\Exception\StorageException;
use ChatFlow\Timer\Timer;
use ChatFlow\Timer\TimerStoreInterface;
use DateTimeImmutable;
use JsonException;

/**
 * One JSON file per timer. Finding the due timers scans the directory, which is fine for the
 * small single-host bots this driver is meant for.
 */
class FileTimerStore implements TimerStoreInterface
{
    public function __construct(private readonly string $storagePath)
    {
        $directory = $this->storagePath . '/timers';

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new StorageException(\sprintf('Failed to create timer directory "%s".', $directory));
        }
    }

    public function schedule(Timer $timer): void
    {
        $path = $this->pathFor($timer->id);
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            $content = json_encode($timer->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new StorageException('Failed to encode timer: ' . $e->getMessage(), 0, $e);
        }

        if (file_put_contents($temporary, $content) === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new StorageException(\sprintf('Failed to write timer "%s".', $timer->id));
        }
    }

    public function cancel(string $id): void
    {
        $path = $this->pathFor($id);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function due(DateTimeImmutable $now, int $limit = 100): array
    {
        $due = array_values(array_filter($this->all(), static fn(Timer $timer): bool => $timer->at <= $now));

        return \array_slice($due, 0, max(0, $limit));
    }

    public function forConversation(string $conversationId): array
    {
        return array_values(array_filter($this->all(), static fn(Timer $timer): bool => $timer->conversationId === $conversationId));
    }

    /**
     * @return list<Timer>
     */
    private function all(): array
    {
        $files = glob($this->storagePath . '/timers/*.json');
        $timers = [];

        foreach ($files === false ? [] : $files as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $timer = \is_array($decoded) ? Timer::fromArray($decoded) : null;

            if ($timer !== null) {
                $timers[] = $timer;
            }
        }

        usort($timers, static fn(Timer $a, Timer $b): int => [$a->at->getTimestamp(), $a->id] <=> [$b->at->getTimestamp(), $b->id]);

        return $timers;
    }

    private function pathFor(string $id): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $id);

        return $this->storagePath . '/timers/' . $safe . '_' . substr(hash('sha256', $id), 0, 12) . '.json';
    }
}
