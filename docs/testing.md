# Testing

## Core Tests

The core suite runs without any platform SDK. It covers routing, middleware order, the scene
lifecycle (enter, back, leave, hooks, actions, interactions), transition guards, rollback on
failure, storage drivers, the container scope, validation, serialization and the platform
boundary.

```bash
composer check
```

## Testing Flows In Your Bot

Use a fake adapter that records delivered effects and build the application with
`MemoryStorage`:

```php
$application = new Application(new FakePlatformAdapter(), new Container());
(new MyFlow())->register($application);

$application->handle(new InboundEvent(new ConversationRef('42'), text: '/start'));
$application->handle(new InboundEvent(new ConversationRef('42'), actionId: 'scene:onCheckout'));

$conversation = $application->getConversations()->resume('42');
self::assertSame(CheckoutScene::class, $conversation->getCurrentScene());
self::assertSame([1 => 2], $conversation->getContext()->get('cart'));
```

`resume()` restores the conversation without side effects, so tests can inspect the current scene,
history (`getContext()->getHistory()`) and session data between updates.

A fake adapter should create inbound events, record delivered effects, declare capabilities and
be able to simulate delivery failures. `tests/Support/FakePlatformAdapter.php` in this repository
is a reference.

## Adapter Tests

Adapter packages should test platform input normalization, capability declaration, delivery of
`reply`, `render` and `ack`, file download where supported, and platform error behaviour.
`tests/Support/PlatformAdapterContractAssertions.php` holds shared assertions.
