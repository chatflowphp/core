# Serialization

Everything that crosses a request boundary must be JSON-compatible:

- session data (`$ctx->session()`),
- action payloads,
- event, view, action, choice and media metadata,
- runtime event data.

Allowed values: `null`, `bool`, `int`, `float`, `string`, arrays of allowed values, and backed
enums (stored as their backing value). Objects, resources, closures and non-backed enums are
rejected with `InvalidArgumentException` (events and views) or
`Automata\Exception\InvalidStateValueException` (session) at write time, never at storage time.

Top-level session keys must be strings that PHP does not convert to integers; nested arrays may
use integer keys, so `['cart' => [1 => 2]]` is fine.

`SerializableValueValidator::normalize()` and `normalizeMap()` implement the rules for adapters
that build their own value objects.
