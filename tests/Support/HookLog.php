<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support;

/**
 * Shared log for scene fixtures, registered in the container so scenes can record their hooks.
 */
final class HookLog
{
    /**
     * @var list<string>
     */
    private array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
