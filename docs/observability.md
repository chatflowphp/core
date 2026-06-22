# Observability

Core emits runtime events through `RuntimeObserverInterface`.

Built-in observers:

- `NullRuntimeObserver`
- `JsonlRuntimeObserver`

## Events

Common events:

- `inbound.received`
- `route.matched`
- `route.missing`
- `scene.matched`
- `scene.entered`
- `effect.queued`
- `effect.delivered`
- `delivery.failed`
- `handler.failed`

## JSONL Logs

```php
use ChatFlow\Observability\JsonlRuntimeObserver;

$observer = new JsonlRuntimeObserver(__DIR__ . '/storage/runtime.jsonl');
```

Telegram `Bot` accepts `runtimeObserver`.

Use JSONL logs during real bot testing. They show whether a visual issue is a route, scene or delivery problem.

## Event Shape

Each event contains:

- timestamp
- name
- conversation id
- data

Do not put secrets or raw tokens into event data.
