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
$ctx->isSystem();            // produced by Application::run(), not by a user
$ctx->isAction();
$ctx->getActionId();
$ctx->getActionPayload();
$ctx->getAttachments();      // list<InboundAttachment>
$ctx->hasAttachment('photo');
$ctx->getFirstAttachment();
$ctx->getMessageRef();       // ?MessageRef
$ctx->getMetadata();
```

## Matched Route And Command Arguments

```php
$ctx->getRoute();              // ?Route, the route the runtime matched
$ctx->getCommandArgument();    // "ref_abc123" for "/start ref_abc123"
```

`getCommandArgument()` returns the text after the command, which is what Telegram deep links
carry. It handles the group form as well (`/start@my_bot ref_abc123`) and returns an empty string
when the event was not routed to a command.

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

## Tick Number

`$ctx->getTickCount()` is the number this tick commits as; hand it to work outside the tick and
pass it back to `Application::run()` as `expectedTick`. See [Long Turns](long-turns.md).

## Side Effects

```php
$ctx->schedule('refund', ['order' => 42]);
```

Records work to run after the tick committed; see [Side Effects](side-effects.md).

## Timers

```php
$ctx->wakeAt(86400, 'silence');
$ctx->cancelTimer('silence');
$ctx->getOccurredAt();   // when the event happened on the platform
```

See [Timers](timers.md).

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

## Localization

```php
$ctx->t('greeting', ['name' => 'Alex']);   // translated in the locale of this event
$ctx->getLocale();                         // resolved by LocaleMiddleware, or null
$ctx->setLocale('ru');
```

`t()` needs a `ChatFlow\I18n\TranslatorInterface` in the container. See
[Localization](i18n.md).

## Request Items

`set()`, `get()`, `has()` and `remove()` keep values for the current request only, for example
data computed by middleware for the handler.

## Container

`getContainer()` returns the runtime container. `Context` and `InboundEventInterface` are bound
into its request scope while the event is handled.
