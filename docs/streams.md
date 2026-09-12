# Streams

A conversation snapshot is one record rewritten on every tick. Some data only grows: the message
history, an audit trail, a journal of what the bot decided. `StreamStorageInterface` stores that
kind of data as append-only streams.

```php
use ChatFlow\Storage\StreamStorageInterface;

$seq = $streams->append('42:messages', ['role' => 'user', 'text' => 'Hello'], id: 'update:1001');
$records = $streams->read('42:messages', fromSeq: $cursor + 1, limit: 50);
$last = $streams->last('42:messages');
$streams->truncate('42:messages');
```

| Method | Meaning |
| --- | --- |
| `append($stream, $data, $id = null)` | adds a record and returns its position, starting at 1 |
| `read($stream, $fromSeq = 1, $limit = null)` | records in order from a position, as `StreamRecord` (`seq`, `data`, `id`) |
| `last($stream)` | position of the last record, 0 when the stream is empty |
| `truncate($stream)` | removes the stream |

## Deduplication

Pass an id when the same record may be appended twice: a webhook retried by the platform, a job
replayed after a crash. The second append with the same id writes nothing and returns the
position of the first. Ids are scoped to the stream.

## Keys

Streams and snapshot records live in separate storages under separate keys. Name a stream after
the conversation it belongs to, `<conversation id>:<name>`, so an application that removes a
conversation knows what to truncate.

## Drivers

| Driver | Use |
| --- | --- |
| `MemoryStreamStorage` | tests and single-process bots |
| `FileStreamStorage` | local development and small single-host bots; one JSON-lines file per stream, an index with the last position and the ids, an exclusive lock per stream; a range read scans the file from the start |
| `RedisStreamStorage` | production; a list per stream and a hash of ids, appended atomically by a Lua script; a stream expires after the driver TTL (default 30 days) counted from its last append |
| `DatabaseStreamStorage` | production with MySQL, PostgreSQL or SQLite through PDO; one row per record, the position is taken inside a transaction |

```php
$pdo->exec(DatabaseStreamStorage::createTableSql('mysql'));   // 'pgsql', 'sqlite'
```

The table (`chatflow_streams` by default) has `stream_key`, `seq`, `dedupe_id`, `record_data`
and `created_at`, with the primary key on `(stream_key, seq)` and a unique index on
`(stream_key, dedupe_id)`.

## What Streams Are Not

Streams are not a message queue and not an event bus: nothing subscribes to them and nothing is
delivered. They are storage that keeps order and never loses a record to a rewrite.
