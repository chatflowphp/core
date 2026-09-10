# Observability

Pass a `RuntimeObserverInterface` to `Application` (or to `ConversationManager`) to receive
lifecycle events as `RuntimeEvent` objects with a name, a conversation id and data.

| Event | Data | When |
| --- | --- | --- |
| `inbound.received` | `is_action`, `action_id`, `text` | at the start of `handle()` |
| `route.matched` | `route_type`, `pattern` | a route will run in the root scene |
| `route.missing` | | no scene, no route |
| `scene.matched` | `scene`, `global_route` | a scene is active |
| `scene.entered` | `scene`, `from` | a transition into a scene committed |
| `scene.left` | `scene`, `to` | a transition out of a scene committed |
| `conversation.reset` | `reason` | a stored snapshot could not be restored and was discarded |
| `effect.queued` | `effect` | `reply()`, `render()`, `ack()` |
| `effect.delivered` | `effect`, `message` | the adapter delivered an effect |
| `delivery.failed` | `effect`, `reason` | the adapter failed |
| `handler.failed` | `exception`, `message` | a tick or middleware threw |

`JsonlRuntimeObserver` appends events as newline-delimited JSON:

```php
use ChatFlow\Observability\JsonlRuntimeObserver;

$observer = new JsonlRuntimeObserver(__DIR__ . '/storage/runtime.jsonl');
```

`NullRuntimeObserver` is the default.
