<?php

declare(strict_types=1);

namespace ChatFlow\Tests;

use ChatFlow\Observability\JsonlRuntimeObserver;
use ChatFlow\Observability\RuntimeEvent;
use PHPUnit\Framework\TestCase;

final class JsonlRuntimeObserverTest extends TestCase
{
    public function test_it_appends_runtime_events_as_json_lines(): void
    {
        $directory = sys_get_temp_dir() . '/chatflow-jsonl-observer-' . bin2hex(random_bytes(6));
        $file = $directory . '/nested/runtime.jsonl';

        try {
            $observer = new JsonlRuntimeObserver($file);
            $observer->record(new RuntimeEvent('inbound.received', 'conv-1', [
                'text' => '/start',
                'is_action' => false,
            ]));
            $observer->record(new RuntimeEvent('effect.delivered', 'conv-1', [
                'effect' => 'reply',
            ]));

            $lines = file($file, FILE_IGNORE_NEW_LINES);

            self::assertIsArray($lines);
            self::assertCount(2, $lines);

            $first = json_decode((string) $lines[0], true, flags: JSON_THROW_ON_ERROR);
            $second = json_decode((string) $lines[1], true, flags: JSON_THROW_ON_ERROR);

            self::assertIsString($first['timestamp'] ?? null);
            self::assertSame('inbound.received', $first['name'] ?? null);
            self::assertSame('conv-1', $first['conversation_id'] ?? null);
            self::assertSame('/start', $first['data']['text'] ?? null);
            self::assertFalse($first['data']['is_action'] ?? true);

            self::assertSame('effect.delivered', $second['name'] ?? null);
            self::assertSame('reply', $second['data']['effect'] ?? null);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }

            if (is_dir($directory . '/nested')) {
                rmdir($directory . '/nested');
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_it_rejects_empty_log_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new JsonlRuntimeObserver('');
    }
}
