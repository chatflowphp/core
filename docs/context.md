# Context

`ChatFlow\Core\Context` is the object handlers, scenes and middleware use during a request.

## Inbound Access

```php
$ctx->getEvent();
$ctx->getConversation();
$ctx->getConversationId();
$ctx->getUser();
$ctx->getUserId();
$ctx->getText();
$ctx->isAction();
$ctx->getActionId();
$ctx->getActionPayload();
$ctx->getAttachments();
$ctx->getFirstAttachment();
$ctx->hasAttachment();
$ctx->getMessageRef();
$ctx->getMetadata();
```

## Outbound Effects

```php
$ctx->reply('Text');
$ctx->reply(View::text('Text'));
$ctx->render(View::text('Updated screen'));
$ctx->ack('Saved');
$ctx->ack('Invalid action', error: true);
```

`reply()` queues a new outgoing message.

`render()` queues a screen update. The adapter decides whether that means edit, replace or send.

`ack()` queues an acknowledgement. Telegram maps this to `answerCallbackQuery()` for callback queries.

## Attachments

Attachments are normalized into `InboundAttachment`:

```php
if ($ctx->hasAttachment('photo')) {
    $photo = $ctx->getFirstAttachment('photo');
}
```

To download through the active adapter:

```php
$path = $ctx->downloadAttachment(__DIR__ . '/storage/uploads');
```

`downloadAttachment()` throws `UnsupportedCapabilityException` if the platform does not support file downloads.

## Session

```php
$session = $ctx->session();
$session->set('step', 'phone');
$value = $session->get('step');
```

`session()` requires a configured `StateManager`. Without storage-backed state it throws `FSMException`.

## Scene Navigation

```php
$ctx->enter(MyScene::class);
$ctx->back();
$ctx->leave();
$ctx->clearHistory();
```

`enter()` requests a scene and processes it immediately.

`back()` returns to the previous scene from history.

`leave()` clears the active scene.

`clearHistory()` removes scene history.

## Runtime Bag

Middleware and handlers can share request-local values:

```php
$ctx->set('visitor', '@alice');
$ctx->get('visitor', 'guest');
$ctx->has('visitor');
$ctx->remove('visitor');
```

This bag is not persisted. Use `session()` for persistent state.
