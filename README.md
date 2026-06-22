# ChatFlow Core

`chatflowphp/core` is the platform-neutral runtime for chat workflows.

It contains routing, middleware, scenes/FSM, sessions, validation, normalized inbound events and platform-neutral outgoing views. It does not depend on Telegram or any other transport SDK.

Full package documentation starts at [docs/index.md](docs/index.md). Use [docs/ai-index.md](docs/ai-index.md) as compact context for AI-assisted flow implementation tasks.

## Runtime Model

Adapters convert platform input into `InboundEvent` with typed refs:

- `ConversationRef` is the stable session key.
- `UserRef` identifies the actor when the platform provides one.
- `MessageRef` carries platform delivery metadata for the adapter.
- `InboundAttachment` represents incoming files/media.

Handlers use `Context`:

```php
$ctx->getConversationId();
$ctx->getUserId();
$ctx->getText();
$ctx->getActionId();
$ctx->reply('Hello');
$ctx->render(View::text('Updated'));
$ctx->ack('Saved');
```

`reply()`, `render()` and `ack()` enqueue outbound effects. `Application` flushes those effects through the platform adapter after the handler, middleware and scene lifecycle complete.

## Shared Flow Contract

Reusable business flows implement `FlowInterface` and register against `FlowRuntimeInterface`.
Adapter facades implement that runtime contract, so portable flow code can be reused when the product semantics are truly the same. Public adapter examples should still be platform-native because every chat platform has different UX and delivery constraints.

## Routing

Core routes are platform-neutral:

```php
$application->onCommand('start', $handler);
$application->onTextPrefix('/search', $handler);
$application->onTextRegex('/^ticket:\d+$/', $handler);
$application->onAction('support:open', $handler);
$application->onActionPrefix('support:', $handler);
$application->onActionRegex('/^scene:/', $handler);
$application->fallback($handler);
```

First registered route wins.

## Adapter Contract

Adapters implement `PlatformAdapterInterface`:

- `createInboundEvent()` normalizes platform input.
- `deliver()` sends `ReplyEffect`, `RenderEffect` and `AckEffect`.
- `downloadAttachment()` handles synchronous file download where supported.
- `capabilities()` declares platform support for actions, choices, media, render, ack and attachment download.

Core enforces capabilities before queuing unsupported effects.

## Observability

Pass a `RuntimeObserverInterface` to `Application` to receive lifecycle events:

- `inbound.received`
- `route.matched`
- `route.missing`
- `scene.matched`
- `scene.entered`
- `effect.queued`
- `effect.delivered`
- `delivery.failed`
- `handler.failed`

Adapters may expose this through their facade constructors.

For local development, `JsonlRuntimeObserver` writes those events as newline-delimited JSON:

```php
use ChatFlow\Observability\JsonlRuntimeObserver;

$observer = new JsonlRuntimeObserver(__DIR__ . '/storage/runtime.jsonl');
```

## Session TTL

`StateManager` accepts an optional `sessionTtlSeconds` value. Expired sessions are deleted from storage and recreated on load.

## Serialization Rules

Action payloads, metadata and session state must contain only scalar values, `null`, arrays of allowed values or `BackedEnum`. Non-backed `UnitEnum`, objects and resources are rejected.

## License

This project is released under the MIT License. See `LICENSE` for details.
