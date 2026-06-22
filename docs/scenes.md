# Scenes

Scenes implement dialog state.

Extend `BaseScene`:

```php
use ChatFlow\Core\Context;
use ChatFlow\FSM\BaseScene;

final class CheckoutScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $this->ask('Send your phone')
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->handle([$this, 'handlePhone']);
    }

    public function handlePhone(Context $ctx): void
    {
        $ctx->reply('Thanks');
        $this->leave();
    }
}
```

## Registering Scenes

```php
$runtime->registerScene(CheckoutScene::class);
```

Scenes require storage:

```php
$bot->useStorage(new FileStorage(__DIR__ . '/storage'));
```

## Entering Scenes

```php
$ctx->enter(CheckoutScene::class, ['cart_items' => $items], 'Cart');
```

`enter()` pushes history when another scene is active, saves the session and immediately processes the new scene.

## Scene Input

When a scene is active, incoming messages and actions are routed to the scene first.

Scene action ids are usually produced by `BaseScene::sceneAction()` and look like:

```text
scene:onMethod
```

## Interactions

`ask()` starts an interaction and can include validation:

```php
$this->ask('Enter email')
    ->validate('email', 'Use a valid email.')
    ->handle([$this, 'handleEmail']);
```

Supported validation depends on `ValidationRegistry`.

## History

Use:

```php
$ctx->back();
$ctx->clearHistory();
```

Use history for user-facing dialog navigation, not for arbitrary business logs.
