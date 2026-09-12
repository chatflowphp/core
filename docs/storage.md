# Storage

Storage persists conversations: the current scene, the session data, the history and the pending
interaction, all inside one automata snapshot per conversation.

## Key

The storage key is `ConversationRef::getId()`. Adapters must provide an already-scoped id;
Telegram uses the chat id.

## Drivers

| Driver | Use |
| --- | --- |
| `MemoryStorage` | tests and single-process bots; the default when nothing is configured |
| `FileStorage` | local development and small single-host bots; per-key locks and atomic writes |
| `RedisStorage` | production; every record expires after the driver TTL (default one day) |
| `DatabaseStorage` | production with MySQL, PostgreSQL or SQLite through PDO |

All drivers implement `StorageInterface` (`get`, `save`, `delete`, `exists`) and store records
exactly as given.

## Concurrent Writes

Two workers can handle the same conversation at once: the user taps a button twice, or a scheduler
acts while a message arrives. Both read the same snapshot, both tick, and a plain write would let
the slower one overwrite the faster one.

Drivers implement `VersionedStorageInterface` for that. The write goes through only when the
stored record still carries the version that was read, which is the snapshot's `tickCount`.

| Driver | What keeps the check and the write together |
| --- | --- |
| `MemoryStorage` | a single process |
| `FileStorage` | the exclusive per-key lock |
| `DatabaseStorage` | a transaction with `SELECT ... FOR UPDATE`, `BEGIN IMMEDIATE` on SQLite |
| `RedisStorage` | a Lua script |

When the record moved on, `ConversationStore` throws `ConversationConflictException`; the runtime
replays the tick on top of the state that won, up to three times, and reports
`conversation_conflict` after that. Nothing is delivered before the snapshot is stored, so the
discarded attempt never reaches the user.

A custom driver that implements only `StorageInterface` keeps working: the conflict is then found
by reading before the write, which catches the ordinary case but cannot rule out a lost update.

**A handler can therefore run more than once for one event.** Keep side effects outside the
conversation idempotent, or key them on something you control, such as an order id.

## Database Schema

```php
$pdo->exec(DatabaseStorage::createTableSql('mysql'));   // 'pgsql', 'sqlite'
```

The table (`chatflow_conversations` by default) has `record_key`, `record_data` and `updated_at`.

## Record Format

A record is `Automata\Snapshot\StateSnapshot::toArray()`:

```json
{
  "schemaVersion": 2,
  "createdAt": "2026-09-10T12:00:00+00:00",
  "tickCount": 7,
  "currentStateId": "App\\Scenes\\CheckoutScene",
  "contextState": {"cart": {"1": 2}, "_history": [{"scene": "App\\Scenes\\ShopScene", "title": "Shop"}]},
  "stateData": {}
}
```

Records that cannot be hydrated (1.x sessions, corrupted data) and snapshots pointing to a scene
that is no longer registered are deleted on first access and the conversation starts over in the
root scene. A `conversation.reset` runtime event is recorded in the latter case.

## TTL

`ConversationManager` accepts `sessionTtlSeconds`. Expired snapshots (by `createdAt`, which is
the time of the last write) are deleted on load.

## Streams

Data that only grows, such as the message history, does not belong in the snapshot. Use
`StreamStorageInterface`, see [Streams](streams.md).

## Other Records

Storage is a generic key-value store: `RateLimitMiddleware` keeps its counters under
`rate_limit:<user id>` in the same storage. Use a distinct prefix for your own records.
