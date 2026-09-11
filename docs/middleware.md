# Middleware

Middleware wraps the tick of every inbound event.

```php
use ChatFlow\Core\Context;
use ChatFlow\Middleware\MiddlewareInterface;

final class VisitorMiddleware implements MiddlewareInterface
{
    public function process(Context $ctx, callable $next): mixed
    {
        $ctx->set('visitor', $ctx->getUser()?->getMeta()['username'] ?? 'guest');

        return $next($ctx);
    }
}
```

## Levels

| Level | Registration | Runs |
| --- | --- | --- |
| global | `$application->middleware([...])` | for every event |
| scene | `BaseScene::getMiddlewares()` | while the scene is active |
| route | `$route->middleware(...)` | when the route runs (root scene, or a global route inside a scene) |

Order: global, scene, route, then the tick.

Middleware is given as instances or class names; class names are resolved through the container.

## Short-Circuiting

A middleware may return a `Result` without calling `$next`:

```php
return Result::error('forbidden');
```

Nothing is persisted in that case. Queue a reply first if the user should be told.

## Included Middleware

- `LoggerMiddleware`: logs every request and its duration.
- `RateLimitMiddleware`: soft per-user limit backed by any storage driver, with an optional
  rejection message.
- `LocaleMiddleware`: resolves the locale of the event through a `LocaleResolverInterface` and
  puts it on the context for `$ctx->t()`. See [Localization](i18n.md).
