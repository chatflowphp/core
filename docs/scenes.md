# Scenes

A scene is one state of the conversation: a screen or a dialog step the user is currently in.
Extend `ChatFlow\Scene\BaseScene`:

```php
use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;
use ChatFlow\View\View;

final class CheckoutScene extends BaseScene
{
    public function __construct(private readonly OrderService $orders) {}

    public function handle(Context $ctx): void
    {
        $ctx->ask('Send your phone')
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->onText('cancel', 'onCancel')
            ->handle('handlePhone');
    }

    public function handlePhone(Context $ctx): void
    {
        $order = $this->orders->create($ctx->session()->getArray('cart'), $ctx->getText());
        $ctx->reply(View::text("Order #{$order->id} placed")->addActionRow($this->sceneAction('Home', 'onHome')));
    }

    public function onCancel(Context $ctx): void
    {
        $ctx->reply('Cancelled');
        $ctx->back();
    }

    public function onHome(Context $ctx): void
    {
        $ctx->leave();
    }
}
```

## Registering

```php
$application->registerScene(CheckoutScene::class, 'Checkout');
```

Scenes are built once through the container (constructor injection works) and reused for every
conversation. **Scenes are stateless**: keep per-user data in `$ctx->session()`, never in
properties.

## Lifecycle

| Hook | When |
| --- | --- |
| `onEnter(Context $ctx)` | the conversation transitions into the scene; defaults to `handle()` |
| `handle(Context $ctx)` | input the scene did not claim otherwise; typically renders the screen again |
| public `on*` methods | scene actions created with `sceneAction()` |
| interaction handlers | the answer accepted by `ask()->...->handle()` |
| `onLeave(Context $ctx)` | the conversation transitions away; the pending interaction is already cleared |

Hooks run inside the tick that triggered the transition. `onEnter()` may reply, ask, or even
enter another scene.

## Input Protocol

When a scene is active, each update is dispatched in this order:

1. A global route (commands by default) if `allowsGlobalRoutes()` is true.
2. A scene action: an action id `scene:onMethod` calls the public `onMethod()` of the active
   scene. Payload keys become named parameters and are also passed as `$params`.
3. The pending interaction: text or media shortcuts first, then validators, then the handler.
4. `handle()`.

Stale buttons from other scenes fall through to `handle()`, which re-renders the screen.

## Scene Actions

```php
$view->addActionRow(
    $this->sceneAction('Add to cart', 'onAddToCart', ['id' => $product->id]),
    $this->sceneAction('Back', 'onBack'),
);

public function onAddToCart(Context $ctx, int $id): void { ... }
```

Only public, non-static methods whose name starts with `on` are callable this way.

## Interactions

```php
$ctx->ask(View::text('Enter email'))
    ->validate('required', 'Email is required.')
    ->validate('email', 'Use a valid email.')
    ->onText(['cancel', '/^stop/i'], 'onCancel')
    ->onMedia('photo', 'onPhoto')
    ->handle('saveEmail');
```

- `validate(rule, error)`: rules come from `ValidationRegistry`; a failed rule replies with the
  error and keeps the interaction.
- `onText(patterns, handler)`: exact match (case-insensitive) or `/regex/`; runs before validation
  and consumes the interaction.
- `onMedia(type, handler)`: an attachment of the type (`any` for all) runs the handler.
- `handle(method)`: stores the interaction. Handlers are method names, `[$this, 'method']` or
  first-class callables of named methods; closures are rejected because they cannot be stored.

## Navigation

```php
$ctx->enter(DetailsScene::class, ['item' => 42], 'Catalog');
$ctx->back();
$ctx->leave();
```

- `enter()` pushes the current scene to history (with the optional title), merges the data into
  the session, runs `onLeave()` of the current scene and `onEnter()` of the target. Entering the
  active scene runs its `onEnter()` again without touching history.
- `back()` returns to the previous scene in history, or to the root scene.
- `leave()` clears history and returns to the root scene.
- `clearHistory()` forgets the path without moving.

Allowed paths can be restricted; see [Transitions](transitions.md).

## Scene Ids

The default id is the class name. Override `getId()` to keep stored conversations valid when the
class moves:

```php
public function getId(): string
{
    return 'checkout';
}
```

`enter()`, `allowTransition()` and the test helpers accept either the class or the id.

## Middleware And Options

- `getMiddlewares()`: middleware applied to every tick while the scene is active.
- `allowsGlobalRoutes()`: return `false` to swallow commands as well.
- `getTitle()`: a display name, by default the short class name.

## Failure Semantics

If a hook throws, the machine restores the scene, the session and the history to what they were
before the tick, drops every queued reply, and hands the exception to the error handler. The user
sees only the error handler's message and stays where they were.
