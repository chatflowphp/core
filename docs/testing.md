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

`ChatFlow\Testing\ApplicationTester` drives an application built on `FakePlatformAdapter` with
synthetic events and asserts on replies, scenes, session data, side effects and timers. It needs
`phpunit/phpunit`.

```php
use Automata\Clock\FrozenClock;
use ChatFlow\Testing\ApplicationTester;
use ChatFlow\Testing\FakePlatformAdapter;

$clock = FrozenClock::at('2026-09-12 10:00:00+00:00');
$adapter = new FakePlatformAdapter();
$application = new Application($adapter, new Container(), timers: new MemoryTimerStore(), clock: $clock);
(new MyFlow())->register($application);

$tester = new ApplicationTester($application, $adapter, clock: $clock);

$tester->as('chat-1', userId: 42)->send('/start')
    ->assertReplied('Welcome')
    ->assertActionOffered('shop:open');

$tester->press('shop:open')
    ->assertScene(ShopScene::class)
    ->assertSessionHas('cart', [])
    ->assertTimerScheduled('abandoned_cart');

$tester->travel(new DateInterval('P1D'));   // moves the frozen clock and runs due timers
$tester->assertSee('Still interested?')->assertNoTimer('abandoned_cart');
```

| Driving | |
| --- | --- |
| `as($conversationId, $userId)` | switch the conversation the following events come from |
| `at($occurredAt)` | stamp the following events with a platform time |
| `send($text)`, `press($actionId, $payload)`, `attach($attachment, $caption)`, `system($reason)` | dispatch one event |
| `travel($to)` | move the `FrozenClock` forward (seconds, interval or point in time) and run due timers |
| `drain()` | run pending side effects now |
| `clear()` | forget recorded deliveries |

| Asserting | |
| --- | --- |
| `assertReplied($text)`, `assertRendered($text)`, `assertSee($substring)`, `assertDontSee()`, `assertNoReply()`, `assertActionOffered($actionId)` | what was delivered |
| `assertResult($status, $message)` | the last `Result` |
| `assertScene($scene)`, `assertNotInScene()` | where the conversation is |
| `assertSessionHas($key, $value)`, `assertSessionMissing($key)` | what it stored |
| `assertSideEffectPending($handler)`, `assertNoSideEffectsPending()`, `assertSideEffectFailed($handler)` | side effects |
| `assertTimerScheduled($reason, $at)`, `assertNoTimer($reason)` | timers |

`getReplies()`, `getLastReply()`, `getTexts()`, `getLastResult()` and `resume()` expose the raw
material for assertions the tester does not have.

Adapter packages wrap the tester with their own event builders; the Telegram package's
`TelegramBotTester` builds real updates and asserts on API requests instead.

### Without The Tester

Use the fake adapter directly; it records delivered effects:

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

`FakePlatformAdapter` accepts ready-made inbound events, records delivered effects in `replies`,
`renders`, `acks` and `effects`, declares full capabilities (or the ones you pass) and simulates
a delivery failure for one effect type (`failEffectType`).

## Listening To A Live Bot

`ChatFlow\Platform\ListeningPlatformAdapter` wraps a real adapter so the application hears the
platform but never answers it: events are parsed by the real adapter, effects are recorded and
reported delivered, the real adapter's `afterHandle()` hook is not called. Feed it the updates of
a bot that is already in production and compare what the new flow would have said.

```php
$listening = new ListeningPlatformAdapter($telegramAdapter, static function (Context $ctx, $effect) use ($log): void {
    $log->append($ctx->getConversationId() . ':shadow', ['effect' => $effect->getType()]);
});
$application = new Application($listening, $container);
```

## Adapter Tests

Adapter packages should test platform input normalization, capability declaration, delivery of
`reply`, `render` and `ack`, file download where supported, and platform error behaviour.
`tests/Support/PlatformAdapterContractAssertions.php` holds shared assertions.
