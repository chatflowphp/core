# Routing

Routes are matched in registration order; the first match wins.

```php
$application->onCommand('start', $handler);            // "/start", "/start args", "/start@my_bot"
$application->onTextPrefix('/search', $handler);
$application->onTextRegex('/^ticket:\d+$/', $handler);
$application->onAction('support:open', $handler);
$application->onActionPrefix('support:', $handler);
$application->onActionRegex('/^scene:/', $handler);
$application->fallback($handler);
```

Every method returns the `Route`, which accepts middleware:

```php
$application->onCommand('admin', $handler)->middleware(AdminOnlyMiddleware::class);
```

## Command Arguments

A command route matches with or without an argument; the handler reads it from the context:

```php
$application->onCommand('start', static function (Context $ctx): void {
    $referral = $ctx->getCommandArgument();   // "ref_abc123" for "/start ref_abc123"
});
```

`Route::commandArgument('start', $text)` does the same parsing outside a handler.

## Where Routes Run

Routes run in the root scene, that is, when no scene is active.

**Global routes** also interrupt an active scene. Commands are global by default; every other
route becomes global with `global()`:

```php
$application->onAction('main:open', $handler)->global();
$application->onCommand('quiz', $handler)->global(false);
```

A scene refuses global routes by returning `false` from `allowsGlobalRoutes()`.

A global route that navigates (`$ctx->enter()`, `$ctx->leave()`) transitions the machine like any
scene method would; one that only replies leaves the scene untouched.

## Handlers

Handlers are callables invoked through the container. Parameters resolve by type hint or by name:

```php
$application->onCommand('start', static function (Context $ctx, OrderService $orders): void {
    $ctx->reply('Open orders: ' . $orders->countOpen());
});
```

Available by name: `ctx`, `context`, `event`.

## Custom Routes

Adapters can run a handler chosen outside the router while keeping middleware, sessions and
rollback:

```php
$application->handle($event, Route::custom('media', $handler));
$application->handle($event, Route::custom('membership', $handler, global: true));
```

## No Match

When no scene is active and no route matches, `handle()` returns `Result::noMatch()` and nothing
is stored. Register `fallback()` to answer unknown input.
