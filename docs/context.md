# Context

`ChatFlow\Core\Context` is passed to route handlers, scene methods and middleware. Handlers
receive it by type hint or as `$ctx` / `$context`.

## Inbound Event

```php
$ctx->getEvent();            // InboundEventInterface
$ctx->getConversation();     // ConversationRef
$ctx->getConversationId();   // string, the storage key
$ctx->getUser();             // ?UserRef
$ctx->getUserId();           // string|int|null
$ctx->getText();
$ctx->isAction();
$ctx->getActionId();
$ctx->getActionPayload();
$ctx->getAttachments();      // list<InboundAttachment>
$ctx->hasAttachment('photo');
$ctx->getFirstAttachment();
$ctx->getMessageRef();       // ?MessageRef
$ctx->getMetadata();
```

## Outbound Effects

```php
$ctx->reply('Text');
$ctx->reply(View::text('Text')->addActionRow(new Action('menu:open', 'Menu')));
$ctx->render(View::text('Updated screen'));
$ctx->ack('Saved');
$ctx->ack('Not allowed', error: true);
$ctx->enqueueEffect($adapterSpecificEffect);
```

Effects are queued and delivered after the tick committed. A failed tick drops them.

`downloadAttachment($dir)` asks the adapter to download the first attachment and returns the
local path, or `null`.

## Session

```php
$session = $ctx->session();   // SceneContext

$session->set('cart', [1 => 2]);
$session->get('cart', []);
$session->has('cart');
$session->remove('cart');
$session->getInt('age');
$session->getString('name', 'guest');
$session->getBool('vip');
$session->getArray('cart');
$session->getList('log');
$session->push('log', 'entry');
$session->increment('visits');
$session->all();              // user keys only
$session->clear();
```

Values must follow the [serialization rules](serialization.md). Keys starting with `_` are
reserved for the runtime and cannot be written.

## Scenes

```php
$ctx->enter(CheckoutScene::class, ['cart_items' => $items], 'Cart');
$ctx->enter('checkout');          // by scene id
$ctx->back();
$ctx->leave();
$ctx->clearHistory();
$ctx->canEnter(CheckoutScene::class);
$ctx->getCurrentScene();          // scene id, RootScene::ID outside scenes
$ctx->inScene();
$ctx->conversation();             // Conversation: machine, history, snapshot access
```

All of these run inside the current tick. See [Scenes](scenes.md).

## Asking For Input

```php
$ctx->ask('Enter email')
    ->validate('email', 'Use a valid email.')
    ->onText('cancel', 'onCancel')
    ->handle('saveEmail');
```

`ask()` sends the question and stores an interaction in the session. The next update from the
conversation goes through the fallbacks, then the validators, then the handler.

## Request Items

`set()`, `get()`, `has()` and `remove()` keep values for the current request only, for example
data computed by middleware for the handler.

## Container

`getContainer()` returns the runtime container. `Context` and `InboundEventInterface` are bound
into its request scope while the event is handled.
