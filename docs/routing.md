# Routing

Core routing is platform-neutral. It matches normalized text and action fields from `InboundEventInterface`.

## Route Methods

Available through `Application`, `FlowRuntimeInterface` and adapter facades:

```php
$runtime->onCommand('start', $handler);
$runtime->onTextPrefix('/search', $handler);
$runtime->onTextRegex('/^order:\d+$/', $handler);
$runtime->onAction('cart:open', $handler);
$runtime->onActionPrefix('cart:', $handler);
$runtime->onActionRegex('/^scene:/', $handler);
$runtime->fallback($handler);
```

## Matching Rules

First registered route wins.

`onCommand('start')` matches:

- `/start`
- `/start extra text`

Action routes use `Context::getActionId()`.

Text routes use `Context::getText()`.

## Handler Parameters

Handlers are called through the container. Common supported parameters:

```php
static function (Context $ctx): void {}
static function (InboundEventInterface $event): void {}
static function (Context $ctx, MyService $service): void {}
```

Core binds aliases:

- `Context::class`
- `InboundEventInterface::class`
- `$ctx`
- `$context`
- `$event`

Adapters may bind additional objects such as `TelegramContext`.

## Scenes Before Routes

When a session has an active scene, that scene receives input before routes are considered.

This lets a dialog handle free text such as phone numbers without global routes stealing the message.

## Fallback

Fallback is useful for `/help`-style bots:

```php
$runtime->fallback(static function (Context $ctx): void {
    $ctx->reply('I did not understand. Type /start.');
});
```

Do not use fallback when silent no-match behavior is desired.
