# AI Index: ChatFlow Core

Use this file as compact context when asking AI to implement or modify ChatFlow bots.

## Active Scope

Active packages:

- `chatflowphp/core`
- `chatflowphp/telegram`

Research packages are not part of the active workspace.

## Core Concepts

`Application` handles one normalized inbound event.

`Context` is passed to handlers, scenes and middleware.

`Router` maps text/actions to handlers.

`BaseScene` models multi-step dialogs.

`Session` stores persistent conversation state.

`View` describes platform-neutral output.

`reply`, `render`, `ack` queue outbound effects.

Adapters deliver effects to a platform.

## Correct Handler Pattern

```php
$runtime->onCommand('start', static function (Context $ctx): void {
    $ctx->reply('Welcome');
});
```

Use `Context` for portable code.

Use adapter-specific context only in adapter packages.

## Correct Scene Pattern

```php
final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $this->ask('Send phone')
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->handle([$this, 'savePhone']);
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('phone', $ctx->getText());
        $ctx->reply('Saved');
        $this->leave();
    }
}
```

## Rules For AI Implementations

- Do not call vendor APIs from core code.
- Do not put Telegram classes into the core repository.
- Use scalar ids in payload/session state.
- Use `View`, `Action`, `Choice`, `MediaAttachment` for output.
- Use `ctx->ack()` for action feedback.
- Use `ctx->render()` for screen updates.
- Use `ctx->reply()` for new messages.
- Register scenes before entering them.
- Configure storage before using scenes.
- Add tests with fake adapters for core changes.

## Common Task Mapping

Create a command: use `onCommand()`.

Create an inline button: add `Action` to a `View`.

Handle a button: use `onAction()` or `onActionPrefix()`.

Build a dialog: create `BaseScene`, use `ask()`.

Persist state: use `ctx->session()`.

Add logging: pass `JsonlRuntimeObserver`.

Validate input: use scene `validate()` or a custom validator.

Send media: add `MediaAttachment` and ensure platform capability.

Download incoming file: use `ctx->downloadAttachment()`.
