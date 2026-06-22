# Architecture

ChatFlow core is a small runtime pipeline around normalized chat input.

The core pipeline:

1. Adapter creates an `InboundEventInterface`.
2. `Application` creates a `Context`.
3. Runtime dependencies are bound into the container.
4. Session is loaded when a `StateManager` exists.
5. Pending scene transitions are applied.
6. Active scene or matching route is selected.
7. Middleware pipeline runs.
8. Route handler or scene handler runs.
9. Session is saved.
10. Queued outbound effects are delivered through the adapter.
11. Container runtime state is flushed.

## Boundaries

Core owns:

- Event model.
- Route matching.
- Middleware order.
- Scene lifecycle.
- Session mutation.
- View model.
- Effect queue.
- Capability enforcement.
- Runtime events.

Adapters own:

- Platform input parsing.
- Delivery to remote APIs.
- Downloading files.
- Platform capability declaration.
- Platform-specific helpers.
- Error mapping around vendor APIs.

## Data Flow

```text
platform update
  -> adapter createInboundEvent()
  -> Application handle()
  -> Context
  -> middleware
  -> route or scene
  -> Context reply/render/ack
  -> queued effects
  -> adapter deliver()
```

## Runtime Dependency Binding

`Application` always binds:

- `ChatFlow\Core\Context`
- `ChatFlow\Contracts\InboundEventInterface`
- `ChatFlow\FSM\StateManager` when configured

Adapters may implement `RuntimeDependencyBinderInterface`.

This is how `chatflowphp/telegram` injects `TelegramContext` for handlers without leaking Telegram classes into core.

## Capability Enforcement

`Context` checks `PlatformCapabilities` before queueing an effect:

- `render()` requires `screenRender`.
- `ack()` requires `ack`.
- actions require `actions`.
- choices require `choices`.
- media require `media`.
- `downloadAttachment()` requires `attachmentDownload`.

Unsupported capabilities fail early with `UnsupportedCapabilityException`.

## Portability

`FlowInterface` and `FlowRuntimeInterface` make it possible to register the same flow on multiple adapters when product semantics are actually the same.

This is a capability, not a requirement. Public examples may remain adapter-native.
