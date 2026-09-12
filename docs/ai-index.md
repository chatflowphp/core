# AI Index: ChatFlow Core

Compact context for implementing bots with ChatFlow 2.x.

## Model

- A conversation is a state machine. Scenes are states; the built-in root scene runs routes.
- One inbound event is one tick. All state changes happen inside the tick.
- `Context::enter()`, `back()`, `leave()` are transitions inside the tick.
- A failing tick rolls back scene, session and queued messages; only the error handler replies.
- Commands are global routes and interrupt scenes unless the scene returns `false` from
  `allowsGlobalRoutes()`.
- Scenes are stateless services; per-user data is in `$ctx->session()`.

## Handler Pattern

```php
$runtime->onCommand('start', static function (Context $ctx): void {
    $ctx->reply(View::text('Welcome')->addActionRow(new Action('shop:open', 'Shop')));
});

$runtime->onAction('shop:open', static function (Context $ctx): void {
    $ctx->ack();
    $ctx->enter(ShopScene::class);
});
```

## Scene Pattern

```php
final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $ctx->ask('Send phone')
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->onText('cancel', 'onCancel')
            ->handle('savePhone');
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('phone', $ctx->getText());
        $ctx->reply('Saved');
        $ctx->leave();
    }

    public function onCancel(Context $ctx): void
    {
        $ctx->back();
    }
}
```

- Slow replies (a model, a report) are two ticks: `handle()` records the input and
  `schedule()`s the work; the result comes back through `onSideEffect()`. See docs/long-turns.md.

## Testing

`ApplicationTester` (needs phpunit) drives a flow: `send()`, `press()`, `travel()` for timers,
`assertReplied()`, `assertScene()`, `assertSessionHas()`, `assertSideEffectPending()`,
`assertTimerScheduled()`. Build the application on `FakePlatformAdapter`.

## Rules

- Register scenes before entering them: `$runtime->registerScene(PhoneScene::class)`.
- Declare transitions when the spec has a screen map: `$runtime->allowTransition($from, $to, $guard)`.
- Use `View`, `Action`, `Choice`, `MediaAttachment` for output; `reply()` for new messages,
  `render()` for screen updates, `ack()` for button feedback.
- Work outside the conversation (refund, CRM, email) is `$ctx->schedule('name', $payload)` with a
  handler registered by `$runtime->registerSideEffect('name', Handler::class)`; it runs after the
  tick committed, survives crashes, and must be idempotent by `SideEffect::$id`.
- Growing data (message history, journals) goes to `StreamStorageInterface`, not the session.
- Later action is `$ctx->wakeAt($when, 'reason')` plus `onTimer()` on the scene or the runtime; a
  scheduler calls `$runtime->runDue()`. `$ctx->getOccurredAt()` is the platform time of the event.
- Session values and payloads: scalars, null, arrays, backed enums only.
- Interaction handlers are method names, never closures.
- A handler can run twice for one event (rollback, or a replay after a concurrent write): keep
  side effects outside the conversation idempotent.
- Deep links and command arguments: `$ctx->getCommandArgument()`.
- Localization: register `ChatFlow\I18n\TranslatorInterface` in the container, add
  `LocaleMiddleware`, translate with `$ctx->t('id', ['name' => $value])`.
- Do not call vendor APIs from core code; adapter classes stay in adapter packages.
- Inspect state in tests with `$application->getConversations()->resume($id)`.

## Task Mapping

| Task | Use |
| --- | --- |
| command | `onCommand()` |
| button | `Action` in a `View`, handled by `onAction()` / `onActionPrefix()` |
| button inside a scene | `$this->sceneAction('Label', 'onMethod', $payload)` |
| multi-step dialog | `BaseScene` with `$ctx->ask()` |
| navigation | `enter()`, `back()`, `leave()` |
| restrict navigation | `allowTransition()` with guards |
| persist state | `$ctx->session()` |
| logging | `JsonlRuntimeObserver` |
| validation | `validate()` on `ask()` or a custom validator |
| incoming file | `$ctx->downloadAttachment()` |
| deep link payload | `$ctx->getCommandArgument()` |
| translated text | `$ctx->t()` with `LocaleMiddleware` |
