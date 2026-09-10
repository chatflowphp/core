# Application Runtime

`ChatFlow\Core\Application` receives normalized inbound events and returns a `Result`. It also
implements `FlowRuntimeInterface`, so flows register directly against it.

## Construction

```php
use ChatFlow\Container\Container;
use ChatFlow\Core\Application;

$application = new Application($adapter, new Container());
```

Optional constructor arguments:

- `Router $router`
- `ConversationManager $conversations` (default: registered scenes, `MemoryStorage`, no TTL)
- `ErrorHandlerInterface $errorHandler` (default: `ExceptionRegistry`)
- `ValidationRegistry $validationRegistry`
- `LoggerInterface $logger`
- `RuntimeObserverInterface $runtimeObserver`

To use persistent storage and a session TTL, build the manager yourself:

```php
use ChatFlow\Scene\ConversationManager;
use ChatFlow\Scene\SceneRegistry;
use ChatFlow\Storage\Drivers\FileStorage;
use ChatFlow\Validation\ValidationRegistry;

$scenes = new SceneRegistry($container);
$validation = new ValidationRegistry($container);
$conversations = new ConversationManager(
    $scenes,
    $validation,
    new FileStorage(__DIR__ . '/storage'),
    sessionTtlSeconds: 86400,
);

$application = new Application($adapter, $container, conversations: $conversations, validationRegistry: $validation);
```

Adapters usually hide this behind a facade; in Telegram it is `ChatFlow\Telegram\Bot`.

## Handling

```php
$result = $application->handle($event);
$result = $application->handle($event, Route::custom('media', $handler));
```

The second form skips the router and uses the given route. A non-global custom route runs only
when no scene is active; mark it `global` to run it inside scenes as well.

Order of work: bind request dependencies, resume the conversation, match the route, run
middleware around one tick, persist, deliver effects, flush the container scope. See
[Architecture](architecture.md).

## Results

| Status | Message | When |
| --- | --- | --- |
| `success` | `route_processed` | a route ran in the root scene |
| `success` | `global_route_processed` | a global route ran inside a scene |
| `success` | `scene_processed` | the active scene consumed the event |
| `no_match` | `null` | no scene is active and no route matched |
| `error` | exception message | a handler, scene or middleware threw |
| `error` | `delivery_failed` | the adapter could not deliver an effect |
| any | middleware value | a middleware returned its own `Result` without calling `$next` |

## Errors

Any exception from middleware, routes or scenes is passed to `ErrorHandlerInterface::handle()`
with the context. Before that, the machine has rolled back the scene and session and the effect
queue was cleared, so partial screens are never sent. Effects queued by the error handler are
delivered.

Register handlers by exception type; the most specific one wins:

```php
$application->onException(ProductNotFoundException::class, static function (Throwable $e, ?Context $ctx): void {
    $ctx?->ack('Product is gone.', true);
});

$application->setErrorHandler(static function (Throwable $e, Context $ctx): void {
    $ctx->reply('Something went wrong. Send /start.');
});
```

Without handlers, `ExceptionRegistry` logs the exception and replies with a generic message, or
with the exception message for `UserFriendlyException`, or with class, message and location when
constructed with `debug: true`.

## Persistence Rules

The snapshot is written after a successful tick, except for conversations that never left the
root scene and stored nothing: unknown chats sending random text do not fill the storage.

Nothing is written when a tick fails or when middleware short-circuits.
