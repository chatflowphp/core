# Side Effects

A tick must stay short and must be safe to roll back. Some work is neither: refunding a payment,
writing to a CRM, sending an email, calling a slow API. `Context::schedule()` records such work
as a **side effect** inside the snapshot, and the runtime executes it after the snapshot is
committed.

```php
$ctx->schedule('refund', ['order' => 42, 'amount' => 1500]);
$ctx->reply('Refund started');
```

```php
$application->registerSideEffect('refund', RefundHandler::class);
```

```php
final class RefundHandler implements SideEffectHandlerInterface
{
    public function handle(SideEffect $effect): ?array
    {
        $receipt = $this->payments->refund($effect->payload['order'], idempotencyKey: $effect->id);

        return ['receipt' => $receipt];   // or null when the conversation does not need to know
    }
}
```

## Guarantees

- **Never on a failed tick.** The effect lives in the conversation snapshot; when the tick rolls
  back, it is gone with the rest of the snapshot.
- **Never lost.** It is stored by the same write that commits the tick. A crash after the commit
  leaves it pending; the next event of that conversation or `Application::drain()` runs it.
- **At least once.** A crash or a concurrent write between the execution and the record of it
  makes the effect run again. `SideEffect::$id` is the idempotency key; handlers pass it to the
  systems they call, or check it themselves.
- **Outside the lock.** Handlers run after persistence, so they may take as long as they need.
  Transport effects (`reply()`, `render()`, `ack()`) are delivered first.

## Ids

The default id is `<conversation>:<tick>:<n>`. Pass your own when the outside world already has
one, such as an order id: scheduling an id that is already pending changes nothing, which makes
a double button press harmless.

## Handlers

A handler is registered under the name used in `schedule()`: an instance, a class name resolved
through the container, or a closure with the same signature. A handler may throw. The effect
then stays pending with the attempt counted and the message kept; it is retried on the next event
or drain and skipped for the rest of the current drain. After five failed attempts (constructor
argument `sideEffectMaxAttempts` of `Application`), or at once when no handler is registered
under its name, the effect moves to the failed list.

```php
$session = $application->getConversations()->resume($id)->getContext();
$session->getSideEffects();          // pending
$session->getFailedSideEffects();    // abandoned, with attempts and the last error
$session->clearFailedSideEffects();
```

## Results

When a handler returns an array, the runtime hands it back to the conversation as one system
tick (`reason: effect:<handler>`), where it can reply, change the session and navigate:

- the active scene, if it implements `SideEffectListenerInterface`:

  ```php
  public function onSideEffect(Context $ctx, SideEffect $effect, array $result): void
  {
      $ctx->reply('Refunded, receipt ' . $result['receipt']);
      $ctx->leave();
  }
  ```

- otherwise the application listener for the handler name:

  ```php
  $application->onSideEffect('refund', static function (Context $ctx, SideEffect $effect, array $result): void { ... });
  ```

- otherwise the result is dropped and a `side_effect.result_dropped` runtime event is recorded.

Effects scheduled inside that tick run in the same drain, up to fifty per drain.

## Draining From A Worker

```php
$executed = $application->drain($conversationId);
```

Runs the pending effects of one conversation now. The runtime drains after every handled event,
so a worker is only needed to finish what a crashed request left behind. Nested drains of the
same conversation are skipped.

## Runtime Events

`side_effect.scheduled`, `side_effect.duplicate`, `side_effect.executed`, `side_effect.failed`,
`side_effect.abandoned`, `side_effect.record_conflict`, `side_effect.result_dropped`.

## Side Effects Versus Outbound Effects

| | Outbound effect | Side effect |
| --- | --- | --- |
| what | a message to the user | work in another system |
| who executes | the platform adapter | a handler you register |
| stored | no, in memory until delivered | yes, in the snapshot |
| after a crash | lost; the user asks again | pending; runs on the next event or drain |
| result | delivery status | data handed back to the scene |
