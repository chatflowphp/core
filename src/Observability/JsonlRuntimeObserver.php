<?php

declare(strict_types=1);

namespace ChatFlow\Observability;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class JsonlRuntimeObserver implements RuntimeObserverInterface
{
    public function __construct(
        private readonly string $filePath,
    ) {
        if (trim($this->filePath) === '') {
            throw new InvalidArgumentException('Runtime log file path must be a non-empty string.');
        }

        $directory = \dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Unable to create runtime log directory "%s".', $directory));
        }
    }

    public function record(RuntimeEvent $event): void
    {
        $payload = [
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            ...$event->toArray(),
        ];

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException(\sprintf('Unable to write runtime log file "%s".', $this->filePath));
        }
    }
}
