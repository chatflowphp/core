<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Storage;

use ChatFlow\Storage\Drivers\DatabaseStreamStorage;
use ChatFlow\Storage\Drivers\FileStreamStorage;
use ChatFlow\Storage\Drivers\MemoryStreamStorage;
use ChatFlow\Storage\StreamStorageInterface;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StreamStorageDriversTest extends TestCase
{
    /**
     * @param callable(): StreamStorageInterface $factory
     */
    #[DataProvider('drivers')]
    public function testRecordsAreAppendedInOrderAndReadBackExactly(callable $factory): void
    {
        $storage = $factory();

        $empty = $storage->read('conv-1:messages');
        self::assertSame(0, $storage->last('conv-1:messages'));
        self::assertSame([], $empty);

        $first = ['role' => 'user', 'text' => 'Привет', 'meta' => ['n' => 1, 'ratio' => 1.0, 'flags' => [true, null]]];
        self::assertSame(1, $storage->append('conv-1:messages', $first));
        self::assertSame(2, $storage->append('conv-1:messages', ['role' => 'bot', 'text' => 'Hi']));
        self::assertSame(3, $storage->append('conv-1:messages', ['role' => 'user', 'text' => 'Bye']));
        self::assertSame(1, $storage->append('conv-2:messages', ['role' => 'user', 'text' => 'Other']));

        self::assertSame(3, $storage->last('conv-1:messages'));

        $records = $storage->read('conv-1:messages');
        self::assertCount(3, $records);
        self::assertSame([1, 2, 3], array_map(static fn($record): int => $record->seq, $records));
        self::assertSame($first, $records[0]->data);
        self::assertNull($records[0]->id);

        $tail = $storage->read('conv-1:messages', 2);
        self::assertSame([2, 3], array_map(static fn($record): int => $record->seq, $tail));

        $window = $storage->read('conv-1:messages', 2, 1);
        self::assertCount(1, $window);
        self::assertSame('Hi', $window[0]->data['text']);

        self::assertSame([], $storage->read('conv-1:messages', 4));
        self::assertSame([], $storage->read('conv-1:messages', 1, 0));
    }

    /**
     * @param callable(): StreamStorageInterface $factory
     */
    #[DataProvider('drivers')]
    public function testAppendingTheSameIdTwiceWritesOnce(callable $factory): void
    {
        $storage = $factory();

        self::assertSame(1, $storage->append('s', ['n' => 1], 'update:100'));
        self::assertSame(1, $storage->append('s', ['n' => 'ignored'], 'update:100'));
        self::assertSame(2, $storage->append('s', ['n' => 2], 'update:101'));
        self::assertSame(1, $storage->append('other', ['n' => 1], 'update:100'));

        $records = $storage->read('s');
        self::assertCount(2, $records);
        self::assertSame(1, $records[0]->data['n']);
        self::assertSame('update:100', $records[0]->id);
        self::assertSame('update:101', $records[1]->id);
    }

    /**
     * @param callable(): StreamStorageInterface $factory
     */
    #[DataProvider('drivers')]
    public function testTruncateRemovesTheStreamAndItsIds(callable $factory): void
    {
        $storage = $factory();
        $storage->append('s', ['n' => 1], 'a');
        $storage->append('s', ['n' => 2]);
        $storage->append('keep', ['n' => 3]);

        $storage->truncate('s');
        $storage->truncate('missing');

        self::assertSame(0, $storage->last('s'));
        self::assertSame([], $storage->read('s'));
        self::assertSame(1, $storage->last('keep'));
        self::assertSame(1, $storage->append('s', ['n' => 4], 'a'));
    }

    /**
     * @param callable(): StreamStorageInterface $factory
     */
    #[DataProvider('drivers')]
    public function testStreamKeysWithUnsafeCharactersDoNotCollide(callable $factory): void
    {
        $storage = $factory();
        $storage->append('a/b', ['v' => 1]);
        $storage->append('a_b', ['v' => 2]);
        $storage->append('a:b', ['v' => 3]);

        self::assertSame(1, $storage->read('a/b')[0]->data['v']);
        self::assertSame(2, $storage->read('a_b')[0]->data['v']);
        self::assertSame(3, $storage->read('a:b')[0]->data['v']);
    }

    /**
     * @return iterable<string, array{callable(): StreamStorageInterface}>
     */
    public static function drivers(): iterable
    {
        yield 'memory' => [static fn(): StreamStorageInterface => new MemoryStreamStorage()];
        yield 'file' => [static fn(): StreamStorageInterface => new FileStreamStorage(sys_get_temp_dir() . '/chatflow-streams-' . bin2hex(random_bytes(4)))];
        yield 'sqlite' => [static function (): StreamStorageInterface {
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec(DatabaseStreamStorage::createTableSql('sqlite'));

            return new DatabaseStreamStorage($pdo);
        }];
    }

    public function testCorruptedLinesAreSkippedAndTheIndexSurvives(): void
    {
        $directory = sys_get_temp_dir() . '/chatflow-streams-' . bin2hex(random_bytes(4));
        $storage = new FileStreamStorage($directory);
        $storage->append('s', ['v' => 1]);
        $storage->append('s', ['v' => 2]);

        $files = glob($directory . '/streams/*.jsonl');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        file_put_contents($files[0], "{not json\n", FILE_APPEND);

        $records = $storage->read('s');
        self::assertCount(2, $records);
        self::assertSame(2, $storage->last('s'));
        self::assertSame(3, $storage->append('s', ['v' => 3]));
        self::assertSame($directory, $storage->getStoragePath());
    }

    public function testMemoryStreamStorageExposesStreams(): void
    {
        $storage = new MemoryStreamStorage();
        $storage->append('a', []);
        $storage->append('b', []);

        self::assertSame(['a', 'b'], $storage->streams());
        $storage->clearAll();
        self::assertSame([], $storage->streams());
    }

    public function testDatabaseSchemaIsAvailableForEveryDriver(): void
    {
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `journal`', DatabaseStreamStorage::createTableSql('mysql', 'journal'));
        self::assertStringContainsString('TIMESTAMP', DatabaseStreamStorage::createTableSql('pgsql'));
        self::assertStringContainsString('chatflow_streams', DatabaseStreamStorage::createTableSql('sqlite'));
    }
}
