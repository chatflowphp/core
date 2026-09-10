# Views And Effects

## View

`ChatFlow\View\View` describes an outgoing message without platform details. Views are
immutable; every `with*` and `add*` call returns a new view.

```php
use ChatFlow\View\Action;
use ChatFlow\View\Choice;
use ChatFlow\View\MediaAttachment;
use ChatFlow\View\View;

$view = View::text('Choose a product')
    ->addActionRow(new Action('product:view', 'Laptop', ['id' => 1]), new Action('product:view', 'Phone', ['id' => 2]))
    ->addActionRow(new Action('docs', 'Documentation', url: 'https://example.com'))
    ->addChoiceRow(new Choice('Yes', 'yes'), new Choice('No', 'no'))
    ->addMedia(new MediaAttachment('image', 'https://example.com/laptop.png'))
    ->withMeta(['telegram' => ['parse_mode' => 'HTML']]);
```

- **Actions** are buttons that send an action id and payload back to the bot, or open a URL.
- **Choices** are quick replies that send their value as text.
- **Media** attaches images, documents and other files by URL, path or platform file id.
- **Meta** carries adapter options and must be serializable.

`ViewSerializer` converts views to arrays.

## Effects

Handlers never send messages directly. They queue effects on the context:

| Method | Effect | Meaning |
| --- | --- | --- |
| `reply()` | `ReplyEffect` | a new message |
| `render()` | `RenderEffect` | replace the current screen where the platform allows editing |
| `ack()` | `AckEffect` | lightweight feedback for a button press |
| `enqueueEffect()` | any `OutboundEffectInterface` | adapter-specific effects |

Effects are delivered in order after the tick committed. If the tick fails they are dropped.
If delivery fails, the remaining effects are skipped and the result is `delivery_failed`.

## Capabilities

`PlatformCapabilities` declares what an adapter supports: actions, choices, media, screen render,
ack and attachment download. `Context` rejects unsupported effects before queueing them.
