<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Storage;

use ChatFlow\Storage\Drivers\DatabaseStorage;
use ChatFlow\Storage\Drivers\FileStorage;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Storage\StorageInterface;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageDriversTest extends TestCase
{
    /**
     * @param callable(): StorageInterface $factory
     */
    #[DataProvider('drivers')]
    public function testRecordsAreStoredExactlyAsGiven(callable $factory): void
    {
        $storage = $factory();
        $record = [
            'schemaVersion' => 2,
            'count' => 1,
            'ratio' => 1.0,
            'text' => 'Привет, мир',
            'nested' => ['cart' => [1 => 2, 7 => 1], 'flags' => [true, false, null]],
        ];

        self::assertNull($storage->get('missing'));
        self::assertFalse($storage->exists('missing'));

        $storage->save('key one', $record);
        self::assertTrue($storage->exists('key one'));
        self::assertSame($record, $storage->get('key one'));

        $storage->save('key one', ['count' => 2]);
        self::assertSame(['count' => 2], $storage->get('key one'));

        $storage->delete('key one');
        $storage->delete('key one');
        self::assertNull($storage->get('key one'));
        self::assertFalse($storage->exists('key one'));
    }

    /**
     * @param callable(): StorageInterface $factory
     */
    #[DataProvider('drivers')]
    public function testKeysWithUnsafeCharactersDoNotCollide(callable $factory): void
    {
        $storage = $factory();
        $storage->save('a/b', ['v' => 1]);
        $storage->save('a_b', ['v' => 2]);
        $storage->save('a:b', ['v' => 3]);

        self::assertSame(['v' => 1], $storage->get('a/b'));
        self::assertSame(['v' => 2], $storage->get('a_b'));
        self::assertSame(['v' => 3], $storage->get('a:b'));
    }

    /**
     * @return iterable<string, array{callable(): StorageInterface}>
     */
    public static function drivers(): iterable
    {
        yield 'memory' => [static fn(): StorageInterface => new MemoryStorage()];
        yield 'file' => [static fn(): StorageInterface => new FileStorage(sys_get_temp_dir() . '/chatflow-storage-' . bin2hex(random_bytes(4)))];
        yield 'sqlite' => [static function (): StorageInterface {
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec(DatabaseStorage::createTableSql('sqlite'));

            return new DatabaseStorage($pdo);
        }];
    }

    public function testCorruptedFilesReadAsMissing(): void
    {
        $directory = sys_get_temp_dir() . '/chatflow-storage-' . bin2hex(random_bytes(4));
        $storage = new FileStorage($directory);
        $storage->save('k', ['v' => 1]);

        $files = glob($directory . '/records/*.json');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        file_put_contents($files[0], '{not json');

        self::assertNull($storage->get('k'));
        self::assertSame($directory, $storage->getStoragePath());
    }

    public function testMemoryStorageExposesKeys(): void
    {
        $storage = new MemoryStorage();
        $storage->save('a', []);
        $storage->save('b', []);

        self::assertSame(['a', 'b'], $storage->keys());
        $storage->clearAll();
        self::assertSame([], $storage->keys());
    }

    public function testDatabaseSchemaIsAvailableForEveryDriver(): void
    {
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `sessions`', DatabaseStorage::createTableSql('mysql', 'sessions'));
        self::assertStringContainsString('TIMESTAMP', DatabaseStorage::createTableSql('pgsql'));
        self::assertStringContainsString('chatflow_conversations', DatabaseStorage::createTableSql('sqlite'));
    }
}
