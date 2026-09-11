# ChatFlow Core Docs

`chatflowphp/core` is the transport-neutral runtime used by `chatflowphp/telegram` and other
adapters. It is responsible for:

- normalized inbound events;
- routing and middleware;
- scenes as states of a per-conversation state machine;
- conversation storage and snapshots;
- validation;
- platform-neutral views and outbound effects;
- runtime observability;
- serialization rules.

It is not responsible for platform API calls, webhook parsing, polling, callback payload storage
or platform-specific rendering. Those belong to adapters.

## Reading Order

1. [Architecture](architecture.md)
2. [Application Runtime](application.md)
3. [Context](context.md)
4. [Routing](routing.md)
5. [Scenes](scenes.md)
6. [Transitions](transitions.md)
7. [Views And Effects](views-effects.md)
8. [Storage](storage.md)
9. [Validation](validation.md)
10. [Localization](i18n.md)
11. [Middleware](middleware.md)
12. [Observability](observability.md)
13. [Serialization](serialization.md)
14. [Testing](testing.md)
15. [Upgrade From 1.x](upgrade-from-1.x.md)
16. [AI Index](ai-index.md)

## Using This Through An Adapter?

Bot authors normally read only [Context](context.md), [Routing](routing.md), [Scenes](scenes.md),
[Transitions](transitions.md), [Views And Effects](views-effects.md), [Storage](storage.md),
[Validation](validation.md) and [Localization](i18n.md). The rest documents the runtime for
adapter authors.

## Main Classes

- `ChatFlow\Core\Application`
- `ChatFlow\Core\Context`
- `ChatFlow\Contracts\FlowInterface`, `FlowRuntimeInterface`
- `ChatFlow\Contracts\PlatformAdapterInterface`, `InboundEventInterface`
- `ChatFlow\Routing\Router`, `Route`
- `ChatFlow\Scene\BaseScene`, `RootScene`, `SceneContext`, `SceneTransitions`
- `ChatFlow\Scene\ConversationManager`, `Conversation`
- `ChatFlow\Storage\StorageInterface` and the drivers in `ChatFlow\Storage\Drivers`
- `ChatFlow\View\View`, `Action`, `Choice`, `MediaAttachment`
- `ChatFlow\I18n\TranslatorInterface`, `ArrayTranslator`, `LocaleResolverInterface`
- `ChatFlow\Outbound\ReplyEffect`, `RenderEffect`, `AckEffect`
- `ChatFlow\Platform\PlatformCapabilities`

## Design Rules

- If a feature needs a platform update, chat id, callback id, file id or API endpoint, it does
  not belong in the core.
- Every inbound event is exactly one tick of the conversation's state machine. Handlers and
  scenes change state only inside that tick.
- Scenes are stateless. Per-user data lives in `$ctx->session()`.
- The core owns no translation catalogue: it resolves a locale and delegates to a translator the
  application registers.
