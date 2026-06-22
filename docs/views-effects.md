# Views And Effects

`View` is the platform-neutral outgoing UI model.

If you are using this through `chatflowphp/telegram`, keep this page for the shared effect model and read Telegram docs for delivery details such as edit-or-send fallbacks and callback acknowledgements.

## View

```php
View::text('Hello')
    ->addActionRow(new Action('menu:open', 'Open menu'))
    ->addChoiceRow(new Choice('Cancel', 'cancel'))
    ->addMedia(new MediaAttachment('image', 'https://example.com/image.jpg'));
```

Fields:

- `text`
- `actions`
- `choices`
- `media`
- `meta`

## Actions

`Action` represents button-like input:

```php
new Action('cart:add', 'Add to cart', ['id' => 10]);
new Action('docs', 'Docs', url: 'https://example.com');
```

Payload must follow serialization rules.

## Choices

`Choice` represents reply-keyboard-like options:

```php
new Choice('Cancel checkout', 'Cancel checkout');
```

## Media

`MediaAttachment` represents outbound media:

```php
new MediaAttachment('image', 'https://example.com/photo.jpg');
```

Adapters decide how to map media types.

Telegram maps `image` to `sendPhoto`.

## Effects

Context methods queue effects:

- `reply()` -> `ReplyEffect`
- `render()` -> `RenderEffect`
- `ack()` -> `AckEffect`

Adapters deliver those effects.

The effect semantics are core-level. The actual wire behavior is adapter-specific.

## Delivery Result

Adapters return `DeliveryResult`.

Success means the adapter accepted delivery.

Error means `Application` stops flushing the effect queue and returns `Result::error('delivery_failed')`.
