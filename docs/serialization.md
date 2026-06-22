# Serialization

Core validates values that may be stored or sent through stable payloads.

Allowed:

- `string`
- `int`
- `float`
- `bool`
- `null`
- arrays containing allowed values
- `BackedEnum`

Not allowed:

- arbitrary objects
- resources
- closures
- non-backed `UnitEnum`

## Where Rules Apply

Rules apply to:

- action payloads
- view metadata
- choice metadata
- media metadata
- inbound metadata where normalized by core helpers
- session state

## Why

Bots often store state in files, Redis or databases and may serialize action payloads through platform callback data.

Strict rules avoid silent unserializable state and unstable object payloads.

## Pattern

Store identifiers and scalar payloads:

```php
new Action('product:add', 'Add', ['id' => 123]);
```

Do not store service objects or entities in payload/session state. Store ids and load domain objects from services.
