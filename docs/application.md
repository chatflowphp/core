# Application Runtime

`ChatFlow\Core\Application` is the main core runtime.

It receives an already-normalized `InboundEventInterface` and returns a `Result`.

## Constructor Dependencies

The application requires:

- `PlatformAdapterInterface`
- `Router`
- `ContainerInterface`
- `ErrorHandlerInterface`
- `ValidationRegistry`
- optional `StateManager`
- optional PSR logger
- optional `RuntimeObserverInterface`

Adapters usually hide this constructor behind a facade. In Telegram this facade is `ChatFlow\Telegram\Bot`.

## Handling Order

The official order is:

1. Record `inbound.received`.
2. Bind runtime dependencies.
3. Load session if storage is configured.
4. Apply pending scene transitions.
5. Pick active scene or matching route.
6. Run middleware.
7. Run route handler or scene handler.
8. Save session.
9. Flush outbound effects in queue order.
10. Flush container runtime state.

## Error Handling

Any exception from middleware, route or scene is handled by `ErrorHandlerInterface`.

If the error handler queues replies or acknowledgements, those effects are flushed too.

If delivery fails, `Application` returns:

```php
Result::error('delivery_failed', [
    'reason' => '...',
    'effect' => 'reply|render|ack',
])
```

## No Match

When no route and no active scene match, the result is `Result::noMatch()`.

Use `Router::fallback()` when the bot should answer unmatched messages.

## Effects

Handlers do not send messages immediately. They call:

- `$ctx->reply(...)`
- `$ctx->render(...)`
- `$ctx->ack(...)`

These queue effects. `Application` flushes them only after the handler and middleware chain completes.

This keeps runtime state consistent before outbound delivery begins.
