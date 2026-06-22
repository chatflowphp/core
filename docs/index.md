# ChatFlow Core Docs

`chatflowphp/core` is the transport-neutral runtime used by `chatflowphp/telegram`.

The core is responsible for:

- Normalized inbound events.
- Routing.
- Middleware.
- Scene/FSM lifecycle.
- Session storage.
- Validation.
- Platform-neutral views and outbound effects.
- Runtime observability.
- Serializable value rules.

The core is not responsible for Telegram Bot API calls, webhook parsing, polling, callback payload token storage, media group collection or Telegram-specific rendering behavior. Those belong to `chatflowphp/telegram`.

## Reading Order

1. [Architecture](architecture.md)
2. [Application Runtime](application.md)
3. [Context](context.md)
4. [Routing](routing.md)
5. [Views And Effects](views-effects.md)
6. [Scenes](scenes.md)
7. [Storage](storage.md)
8. [Validation](validation.md)
9. [Middleware](middleware.md)
10. [Observability](observability.md)
11. [Serialization](serialization.md)
12. [Testing](testing.md)
13. [AI Index](ai-index.md)

## Main Classes

- `ChatFlow\Core\Application`
- `ChatFlow\Core\Context`
- `ChatFlow\Contracts\PlatformAdapterInterface`
- `ChatFlow\Contracts\InboundEventInterface`
- `ChatFlow\Contracts\FlowRuntimeInterface`
- `ChatFlow\Contracts\FlowInterface`
- `ChatFlow\Routing\Router`
- `ChatFlow\FSM\BaseScene`
- `ChatFlow\FSM\StateManager`
- `ChatFlow\Storage\Session`
- `ChatFlow\View\View`
- `ChatFlow\View\Action`
- `ChatFlow\View\Choice`
- `ChatFlow\View\MediaAttachment`
- `ChatFlow\Outbound\ReplyEffect`
- `ChatFlow\Outbound\RenderEffect`
- `ChatFlow\Outbound\AckEffect`
- `ChatFlow\Platform\PlatformCapabilities`

## Design Rule

If a feature needs a Telegram update, Telegram chat id, Telegram callback query id, Telegram file id or Telegram API endpoint, it does not belong in core.

Core should only expose abstractions that can be implemented by another adapter without importing Telegram classes.
