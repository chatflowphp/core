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

## Other Records

Storage is a generic key-value store: `RateLimitMiddleware` keeps its counters under
`rate_limit:<user id>` in the same storage. Use a distinct prefix for your own records.
