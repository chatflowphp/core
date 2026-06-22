# Middleware

Middleware wraps route and scene execution.

Implement `MiddlewareInterface`:

```php
use ChatFlow\Core\Context;
use ChatFlow\Middleware\MiddlewareInterface;

final class VisitorMiddleware implements MiddlewareInterface
{
    public function process(Context $ctx, callable $next): mixed
    {
        $ctx->set('visitor', (string) $ctx->getUserId());

        return $next($ctx);
    }
}
```

Register globally:

```php
$runtime->middleware([
    VisitorMiddleware::class,
]);
```

## Order

Middleware runs in registration order.

Route-specific middleware is appended after global middleware.

Scene-specific middleware is appended after global middleware.

## Use Cases

- Visitor labels.
- Access checks.
- Rate limits.
- Logging.
- Per-request service setup.
- Locale detection.

## What Not To Do

Do not store long-lived data in the runtime bag. Use session storage.

Do not call Telegram SDK from core middleware if the middleware is intended to be portable. Use Telegram-only middleware in `chatflowphp/telegram`.
