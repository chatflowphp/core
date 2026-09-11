# Upgrade From 1.x

2.0 has no backward compatibility. Stored 1.x sessions are discarded on first access; users start
in the root scene.

## Dependencies

- `chatflowphp/automata` `^2.0` is required.
- `monolog/monolog` and `vlucas/phpdotenv` are no longer required by the core. Add them to your
  project if you use them.

## Class Map

| 1.x | 2.0 |
| --- | --- |
| `ChatFlow\FSM\BaseScene` | `ChatFlow\Scene\BaseScene` |
| `ChatFlow\FSM\SceneRegistry` | `ChatFlow\Scene\SceneRegistry` |
| `ChatFlow\FSM\StateManager` | `ChatFlow\Scene\ConversationManager` |
| `ChatFlow\FSM\Interaction` | `ChatFlow\Scene\Interaction` |
| `ChatFlow\Storage\Session` | `ChatFlow\Scene\SceneContext` (data) and `ChatFlow\Scene\Conversation` (scene, history) |
| `ChatFlow\FSM\Interop\SessionContext`, `ContextInput` | removed (`SceneContext`, `SceneInput`) |
| `ChatFlow\Exception\FSMException` | `ChatFlow\Exception\SceneException` |
| `ChatFlow\Config\*`, `ChatFlow\Provider\*`, `ChatFlow\I18n\*` | removed |

## Scenes

| 1.x | 2.0 |
| --- | --- |
| `$this->ask(...)` | `$ctx->ask(...)` |
| `$this->leave()` | `$ctx->leave()` |
| `$this->ack()` | `$ctx->ack()` |
| `$this->sceneAction(...)` | unchanged |
| `$this->getContext()`, `$this->getSession()` | use the `Context $ctx` parameter and `$ctx->session()` |
| `onEnter(ContextInterface)` / `onLeave(ContextInterface)` | `onEnter(Context $ctx)` / `onLeave(Context $ctx)` |
| commands inside a scene call `handle()` | commands are global routes; return `false` from `allowsGlobalRoutes()` to keep the old behaviour |
| `getMiddlewares(): array<int, mixed>` | `getMiddlewares(): list<MiddlewareInterface|class-string>` |

Scenes are instantiated once and shared by all conversations. Remove per-user state from
properties.

## Sessions

| 1.x | 2.0 |
| --- | --- |
| `$ctx->session()->get('key')` | unchanged, plus typed getters (`getInt()`, `getString()`, `push()`, `increment()`) |
| `$ctx->session()->getCurrentScene()` | `$ctx->getCurrentScene()` (returns `RootScene::ID` instead of `null`) |
| `$ctx->session()->hasScene()` | `$ctx->inScene()` |
| `$ctx->session()->pushHistory()` etc. | `$ctx->session()->getHistory()`, `pushHistory()`, `popHistory()`, `clearHistory()` |
| `requestScene()`, `requestExit()` inside handlers | `enter()`, `back()`, `leave()` transition immediately |
| `StateManager::enterScene()` / `exitScene()` from outside a request | `ConversationManager::enterLater()` / `leaveLater()` (applied on the next event) or `Application::enter()` / `leave()` / `run()` (applied now, with delivery) |
| `$ctx->getSession()` (nullable) | `$ctx->session()` (throws outside of `Application::handle()`) |

## Navigation Semantics

- `enter()` runs `onLeave()` of the current scene and `onEnter()` of the target inside the current
  tick. Code after `enter()` in the caller keeps running in the caller.
- `back()` with empty history goes to the root scene (1.x did nothing).
- `leave()` clears history (1.x kept it).
- A failing handler no longer sends the replies queued before the failure, and the scene and
  session are restored. The default error handler acknowledges a failed button press as an
  alert instead of sending a message.

## Application

| 1.x | 2.0 |
| --- | --- |
| `new Application($adapter, $router, $container, $errorHandler, $validationRegistry, $stateManager, ...)` | `new Application($adapter, $container, router: ..., conversations: ..., errorHandler: ..., validationRegistry: ..., logger: ..., runtimeObserver: ...)` |
| `Application::getStateManager()` | `Application::getConversations()` |
| `StateManager::loadSession($id)` | `ConversationManager::resume($id)` |
| `ExceptionRegistry($logger, $config)` | `ExceptionRegistry($logger, debug: bool)` |
| `ContainerInterface::bind()` / `set()` after build (flushed) | `set()` (persistent) and `scoped()` (flushed) |
| `FlowRuntimeInterface::getStateManager()` | `getScenes()`, `getTransitions()`, `allowTransition()` |

## Storage

`StorageInterface` methods take a generic `$key`. Drivers no longer wrap or reshape records.
`DatabaseStorage` uses the columns `record_key`, `record_data`, `updated_at`
(`DatabaseStorage::createTableSql()`); the 1.x `chatflow_sessions` table is not reused.

## Routing

- `/command@bot_username` now matches command routes.
- `Route` exposes `isGlobal()` / `global()`; `Router::add()` accepts prebuilt routes;
  `Route::custom()` builds adapter routes.
