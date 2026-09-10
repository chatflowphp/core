<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Scene;

use Automata\Snapshot\StateSnapshot;
use ChatFlow\Scene\ConversationStore;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Tests\Support\MutableClock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConversationStoreTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $storage = new MemoryStorage();
        $store = new ConversationStore($storage);
        $snapshot = StateSnapshot::create(['name' => 'Alex'], 'menu', [], 2, new \DateTimeImmutable('2026-09-10 12:00:00'));

        self::assertNull($store->load('c'));
        $store->save('c', $snapshot);

        self::assertSame($snapshot->toArray(), $store->load('c')?->toArray());
        self::assertSame(['c'], $storage->keys());

        $store->delete('c');
        self::assertNull($store->load('c'));
    }

    public function testRecordsThatCannotBeHydratedAreDeleted(): void
    {
        $storage = new MemoryStorage();
        $storage->save('legacy', ['meta' => ['current_scene' => 'x'], 'data' => []]);
        $store = new ConversationStore($storage);

        self::assertNull($store->load('legacy'));
        self::assertFalse($storage->exists('legacy'));
    }

    public function testExpiredSnapshotsAreDeleted(): void
    {
        $clock = new MutableClock('2026-09-10 12:00:00');
        $storage = new MemoryStorage();
        $store = new ConversationStore($storage, 60, $clock);
        $store->save('c', StateSnapshot::create([], 'menu', [], 1, $clock->now()));

        $clock->advance(60);
        self::assertNotNull($store->load('c'));

        $clock->advance(1);
        self::assertNull($store->load('c'));
        self::assertFalse($storage->exists('c'));
    }

    public function testTtlMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConversationStore(new MemoryStorage(), 0);
    }
}
