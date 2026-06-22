# Storage

Storage persists sessions used by scenes and user state.

## Session Key

The storage key is:

```php
ConversationRef::getId()
```

Adapters must provide an already-scoped conversation id. Telegram uses chat id.

## Drivers

Core includes:

- `MemoryStorage`
- `FileStorage`
- `RedisStorage`
- `DatabaseStorage`

Use `MemoryStorage` for tests only.

Use `FileStorage` for local examples and small bots.

Use Redis or database storage for long-running production bots.

## StateManager

`StateManager` connects:

- `SceneRegistry`
- `StorageInterface`
- optional session TTL

Adapter facades usually create it through `useStorage()`.

## Session Values

Session data must be serializable under core rules:

- scalar
- `null`
- arrays of allowed values
- `BackedEnum`

Objects, resources and non-backed enums are invalid.

## TTL

`StateManager` supports optional session TTL. Expired sessions are deleted and recreated on load.

Use TTL for bots where abandoned dialogs should reset automatically.
