# ChatFlow Core

[![CI](https://github.com/chatflowphp/core/actions/workflows/ci.yml/badge.svg)](https://github.com/chatflowphp/core/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-tested-brightgreen.svg)](https://phpunit.de/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

`chatflowphp/core` is the platform-neutral runtime for chat bots. It turns every conversation into
a state machine: scenes are states, an inbound update is one tick, and navigating between screens
or dialog steps is a transition.

The core owns routing, middleware, scenes, sessions, validation, the normalized inbound event
model and platform-neutral outgoing views. It does not talk to Telegram or any other transport;
adapters such as `chatflowphp/telegram` do.

Documentation starts at [docs/index.md](docs/index.md). Coming from 1.x? Read
[docs/upgrade-from-1.x.md](docs/upgrade-from-1.x.md).

## Why a State Machine

Chat conversations are long-lived and the process handling them is not. Users press buttons on
old messages, send `/start` in the middle of a dialog, click twice, answer with text where a
button was expected. The only reliable way to handle this is to know exactly where every user is
and to let input move them only along defined paths.

`chatflowphp/automata` provides that machine. The core maps it to bot concepts:

| Bot practice | Runtime behaviour |
| --- | --- |
| The user is "on a screen" or "in a dialog step" | The current scene is stored in a snapshot per conversation |
| The same text means different things on different screens | Input always goes to the current scene |
| Entering a screen renders it, leaving it cleans up | `onEnter()` and `onLeave()` hooks |
| The screen map in the bot spec | An optional transition table with guards |
| Back and cancel buttons | Scene history |
| "On validation error the user stays where they were" | A failed tick rolls back the scene, the session and every queued message |
| Commands must work everywhere | Global routes interrupt any scene unless the scene opts out |

## Quick Look

```php
use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;
use ChatFlow\View\View;

final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $ctx->ask('Send your phone in +79991234567 format')
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->onText('cancel', 'onCancel')
            ->handle('savePhone');
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('phone', $ctx->getText());
        $ctx->reply(View::text('Saved')->addActionRow($this->sceneAction('Back', 'onBack')));
    }

    public function onBack(Context $ctx): void
    {
        $ctx->back();
    }

    public function onCancel(Context $ctx): void
    {
        $ctx->leave();
    }
}
```

```php
$application->registerScene(PhoneScene::class);
$application->allowTransition(RootScene::ID, PhoneScene::class);

$application->onCommand('phone', static function (Context $ctx): void {
    $ctx->enter(PhoneScene::class);
});

$application->onCommand('start', static function (Context $ctx): void {
    $ctx->reply('Hello');
});
```

Sending `/start` while `PhoneScene` is active runs the command and keeps the scene. Sending
`cancel` leaves it. Sending anything else asks again.

## Runtime Model

1. The adapter converts platform input into an `InboundEvent` with a `ConversationRef`, an
   optional `UserRef`, a `MessageRef` and attachments.
2. `Application::handle()` resumes the conversation from storage (or starts it in the root scene),
   matches a route, runs middleware and ticks the state machine once.
3. Route handlers and scenes reply through `Context`: `reply()`, `render()`, `ack()`. Effects are
   queued and delivered by the adapter only after the tick committed.
4. The conversation snapshot is written back through `StorageInterface`.

## Serialization Rules

Session data, action payloads, metadata and view metadata may contain scalars, `null`, arrays of
those and backed enums. Objects, resources and non-backed enums are rejected on write.

## Requirements

PHP 8.2 or newer, `chatflowphp/automata` 2.x, `php-di/php-di` 7.x.

## License

MIT. See [LICENSE](LICENSE).
