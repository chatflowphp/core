# ChatFlow Core Docs

`chatflowphp/core` is the transport-neutral runtime used by `chatflowphp/telegram` and other
adapters. It is responsible for:

- normalized inbound events;
- routing and middleware;
- scenes as states of a per-conversation state machine;
- conversation storage, snapshots and append-only streams;
- validation;
- platform-neutral views and outbound effects;
- side effects that run after the tick committed;
- timers that wake a conversation later;
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
8. [Side Effects](side-effects.md)
9. [Timers](timers.md)
10. [Storage](storage.md)
11. [Streams](streams.md)
12. [Validation](validation.md)
13. [Localization](i18n.md)
14. [Middleware](middleware.md)
15. [Observability](observability.md)
16. [Serialization](serialization.md)
17. [Testing](testing.md)
18. [Upgrade From 1.x](upgrade-from-1.x.md)
19. [AI Index](ai-index.md)

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
- `ChatFlow\Storage\StorageInterface`, `StreamStorageInterface` and the drivers in `ChatFlow\Storage\Drivers`
- `ChatFlow\View\View`, `Action`, `Choice`, `MediaAttachment`
- `ChatFlow\I18n\TranslatorInterface`, `ArrayTranslator`, `LocaleResolverInterface`
- `ChatFlow\Outbound\ReplyEffect`, `RenderEffect`, `AckEffect`
- `ChatFlow\SideEffect\SideEffect`, `SideEffectHandlerInterface`, `SideEffectListenerInterface`
- `ChatFlow\Timer\Timer`, `TimerStoreInterface`, `TimerListenerInterface`
- `ChatFlow\Platform\PlatformCapabilities`, `ListeningPlatformAdapter`
- `ChatFlow\Testing\ApplicationTester`, `FakePlatformAdapter`

## Design Rules

- If a feature needs a platform update, chat id, callback id, file id or API endpoint, it does
  not belong in the core.
- Every inbound event is exactly one tick of the conversation's state machine. Handlers and
  scenes change state only inside that tick.
- Scenes are stateless. Per-user data lives in `$ctx->session()`.
- The core owns no translation catalogue: it resolves a locale and delegates to a translator the
  application registers.
