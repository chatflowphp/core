# Transitions

By default a conversation may move from any scene to any scene. Declaring transitions turns the
screen map of your bot spec into an enforced graph.

```php
use ChatFlow\Scene\RootScene;
use ChatFlow\Scene\SceneContext;
use ChatFlow\Scene\SceneTransitions;

$application->allowTransition(RootScene::ID, ShopScene::class);
$application->allowTransition(ShopScene::class, DetailsScene::class);
$application->allowTransition(ShopScene::class, CheckoutScene::class, static fn (SceneContext $c): bool => $c->getArray('cart') !== []);
$application->allowTransition(SceneTransitions::ANY, HelpScene::class);
```

Or declaratively:

```php
$application->getTransitions()->define([
    RootScene::ID => [ShopScene::class],
    ShopScene::class => [
        DetailsScene::class,
        CheckoutScene::class => static fn (SceneContext $c): bool => $c->getArray('cart') !== [],
    ],
    SceneTransitions::ANY => [HelpScene::class],
]);
```

## Rules

Once at least one transition is declared:

- `enter()` must follow a declared edge, and its guard (if any) must return `true`;
- `SceneTransitions::ANY` as source matches every scene, including root;
- `leave()` (to the root scene) is always allowed;
- `back()` to the previous scene in history is always allowed;
- entering the active scene again is not a transition and is always allowed.

A forbidden transition throws `Automata\Exception\InvalidTransitionException`, the tick rolls
back and the error handler runs. Use `$ctx->canEnter($scene)` to hide buttons the user cannot
follow.

Scenes are referenced by class or by scene id; ids are resolved lazily, so declare transitions in
any order relative to `registerScene()`.

## Diagram

```php
echo $application->getTransitions()->toMermaid();
```

renders a Mermaid `stateDiagram-v2` of the declared graph, guarded edges marked. Keep it next to
the bot spec so the screen map and the code agree.
