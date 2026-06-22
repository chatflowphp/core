# Testing

Core is tested without Telegram SDK.

Use fake adapters for core behavior and adapter-specific testers for platform behavior.

## Core Tests

Core tests should verify:

- route matching.
- middleware order.
- scene lifecycle.
- validation.
- effect queue order.
- delivery failure handling.
- serialization rules.
- platform boundary.

## Fake Platform Adapter

A fake adapter should:

- create `InboundEvent` objects.
- record delivered effects.
- declare capabilities.
- simulate delivery failures.

## Adapter Tests

Adapter packages should test:

- platform input normalization.
- capability declaration.
- delivery of `reply`, `render`, `ack`.
- file download where supported.
- platform-specific error behavior.

Telegram uses `TelegramBotTester` and `MockHttpClient`.
