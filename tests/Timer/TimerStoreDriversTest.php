<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Timer;

use ChatFlow\Timer\Drivers\DatabaseTimerStore;
use ChatFlow\Timer\Drivers\FileTimerStore;
use ChatFlow\Timer\Drivers\MemoryTimerStore;
use ChatFlow\Timer\Timer;
use ChatFlow\Timer\TimerStoreInterface;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimerStoreDriversTest extends TestCase
{
    /**
     * @param callable(): TimerStoreInterface $factory
     */
    #[DataProvider('drivers')]
    public function testDueTimersComeBackEarliestFirstAndOnlyWhenDue(callable $factory): void
    {
        $store = $factory();
        $t0 = new DateTimeImmutable('2026-09-12 10:00:00+00:00');

        $store->schedule(new Timer('c1:late', 'c1', $t0->modify('+2 hours'), 'late'));
        $store->schedule(new Timer('c1:soon', 'c1', $t0->modify('+10 minutes'), 'soon', ['n' => 1, 'text' => 'Привет']));
        $store->schedule(new Timer('c2:now', 'c2', $t0, 'now'));

        self::assertSame([], $store->due($t0->modify('-1 second')));
        self::assertSame(['c2:now'], self::ids($store->due($t0)));
        self::assertSame(['c2:now', 'c1:soon'], self::ids($store->due($t0->modify('+1 hour'))));
        self::assertSame(['c2:now'], self::ids($store->due($t0->modify('+1 hour'), 1)));
        self::assertSame([], $store->due($t0->modify('+1 hour'), 0));

        $due = $store->due($t0->modify('+1 hour'));
        self::assertSame(['n' => 1, 'text' => 'Привет'], $due[1]->payload);
        self::assertSame('soon', $due[1]->reason);
        self::assertSame('c1', $due[1]->conversationId);
        self::assertSame($t0->modify('+10 minutes')->getTimestamp(), $due[1]->at->getTimestamp());
    }

    /**
     * @param callable(): TimerStoreInterface $factory
     */
    #[DataProvider('drivers')]
    public function testSchedulingAnExistingIdMovesTheTimerAndCancelRemovesIt(callable $factory): void
    {
        $store = $factory();
        $t0 = new DateTimeImmutable('2026-09-12 10:00:00+00:00');

        $store->schedule(new Timer('c1:silence', 'c1', $t0->modify('+1 day'), 'silence'));
        $store->schedule(new Timer('c1:silence', 'c1', $t0->modify('+2 days'), 'silence', ['moved' => true]));
        $store->schedule(new Timer('c1:other', 'c1', $t0->modify('+3 days'), 'other'));
        $store->schedule(new Timer('c9:x', 'c9', $t0, 'x'));

        self::assertSame(['c9:x'], self::ids($store->due($t0->modify('+1 day'))), 'The moved timer is not due yet.');
        self::assertSame(['c9:x', 'c1:silence'], self::ids($store->due($t0->modify('+2 days'))));
        self::assertSame(['c1:silence', 'c1:other'], self::ids($store->forConversation('c1')));
        self::assertSame(['moved' => true], $store->forConversation('c1')[0]->payload);

        $store->cancel('c1:silence');
        $store->cancel('missing');

        self::assertSame(['c1:other'], self::ids($store->forConversation('c1')));
        self::assertSame(['c9:x', 'c1:other'], self::ids($store->due($t0->modify('+10 days'))));
    }

    /**
     * @return iterable<string, array{callable(): TimerStoreInterface}>
     */
    public static function drivers(): iterable
    {
        yield 'memory' => [static fn(): TimerStoreInterface => new MemoryTimerStore()];
        yield 'file' => [static fn(): TimerStoreInterface => new FileTimerStore(sys_get_temp_dir() . '/chatflow-timers-' . bin2hex(random_bytes(4)))];
        yield 'sqlite' => [static function (): TimerStoreInterface {
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            foreach (explode(';', DatabaseTimerStore::createTableSql('sqlite')) as $statement) {
                if (trim($statement) !== '') {
                    $pdo->exec($statement);
                }
            }

            return new DatabaseTimerStore($pdo);
        }];
    }

    public function testTimerRoundTripsThroughItsArrayForm(): void
    {
        $timer = new Timer('id', 'conv', new DateTimeImmutable('2026-09-12 10:00:00+03:00'), 'reason', ['a' => ['b' => 1]]);
        $restored = Timer::fromArray($timer->toArray());

        self::assertNotNull($restored);
        self::assertSame($timer->at->getTimestamp(), $restored->at->getTimestamp());
        self::assertSame('2026-09-12T07:00:00+00:00', $timer->toArray()['at']);
        self::assertSame(['a' => ['b' => 1]], $restored->payload);
        self::assertNull(Timer::fromArray(['id' => 'x']));
        self::assertNull(Timer::fromArray(['id' => 'x', 'conversation' => 'c', 'at' => 'yesterday', 'reason' => 'r']));
    }

    public function testDatabaseSchemaIsAvailableForEveryDriver(): void
    {
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `wakeups`', DatabaseTimerStore::createTableSql('mysql', 'wakeups'));
        self::assertStringContainsString('TIMESTAMP', DatabaseTimerStore::createTableSql('pgsql'));
        self::assertStringContainsString('chatflow_timers', DatabaseTimerStore::createTableSql('sqlite'));
    }

    /**
     * @param list<Timer> $timers
     *
     * @return list<string>
     */
    private static function ids(array $timers): array
    {
        return array_map(static fn(Timer $timer): string => $timer->id, $timers);
    }
}
