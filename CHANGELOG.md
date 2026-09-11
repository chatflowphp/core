# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `Context::getRoute()` and `Context::getCommandArgument()`, plus `Route::commandArgument()`:
  handlers read the argument of the matched command, which is what deep links carry
  ("/start ref_abc123").
- `ChatFlow\I18n`: `TranslatorInterface` with `ArrayTranslator` and `SymfonyTranslatorAdapter`,
  locale resolution through `LocaleResolverInterface`, `SessionLocaleResolver` and
  `ChainLocaleResolver`, `Middleware\LocaleMiddleware`, and `Context::t()` / `getLocale()` /
  `setLocale()`. See `docs/i18n.md`.

## [2.0.0] - 2026-09-10

A rewrite on top of `chatflowphp/automata` 2.0. Conversations are state machines: scenes are
states, every inbound event is one tick. There is no backward compatibility with 1.x; see
`docs/upgrade-from-1.x.md`.

### Added

- `ChatFlow\Scene` namespace: `BaseScene`, `RootScene`, `SceneContext`, `SceneRegistry`,
  `SceneTransitions`, `Conversation`, `ConversationManager`, `ConversationStore`, `Interaction`.
- Transition tables with guards through `FlowRuntimeInterface::allowTransition()` and
  `SceneTransitions::define()`; Mermaid rendering of the scene graph.
- Global routes: commands interrupt active scenes, other routes opt in with `Route::global()`;
  scenes opt out with `allowsGlobalRoutes()`.
- Atomic ticks: a failure inside a scene or route handler rolls back the scene, the session and
  the queued outbound effects; only the error handler replies.
- Scene ids (`BaseScene::getId()`) independent of class names.
- Typed session accessors (`getInt()`, `getString()`, `push()`, `increment()`, ...) inherited from
  automata.
- `Route::custom()` and `Application::handle($event, $route)` so adapters can run a handler
  outside the router while keeping middleware, sessions and rollback.
- Request-scoped container bindings (`ContainerInterface::scoped()`), type-hint injection of
  scoped instances into handlers, and self-registration of the container.
- `DatabaseStorage::createTableSql()`.
- `UnsupportedInputException` for adapters that cannot map an update to a conversation.
- Runtime events `scene.entered`, `scene.left`, `conversation.reset`, `scene.pending_applied`,
  `scene.pending_failed`.
- Scene transitions from outside a request: `ConversationManager::enterLater()` /
  `leaveLater()` apply on the conversation's next event; `Application::enter()` / `leave()` /
  `run()` apply now through a `SystemEvent` tick with delivery. `Context::isSystem()`.
- `AfterHandleInterface` for adapters that need to act after every handled event.

### Changed

- `Application` implements `FlowRuntimeInterface` and no longer needs a state manager: a
  `ConversationManager` with `MemoryStorage` is created by default.
- `Context::enter()`, `back()` and `leave()` are state machine transitions executed inside the
  current tick. `leave()` clears history; `back()` with empty history returns to the root scene.
- `ask()` moved from `BaseScene` to `Context`; `sceneAction()` stays on `BaseScene`.
- Scenes are stateless services created once through the container.
- Storage drivers store records exactly as given; the conversation record is the automata
  snapshot. Old 1.x records are discarded on first access.
- `ExceptionRegistry` resolves handlers by specificity (class, parents, interfaces), takes a
  `bool $debug` flag instead of a config object, and acknowledges failed button presses as alerts.
- `ext-mbstring` is declared as a requirement.
- Commands match the group form `/command@bot_username`.
- Events, views and runtime events normalize their payloads once on construction.
- Tooling: PHPStan level max with strict rules, PER-CS 2.0, strict PHPUnit configuration,
  prefer-lowest CI job, Dependabot.

### Removed

- `ChatFlow\FSM` namespace, `StateManager`, `Storage\Session`, `SessionContext`, `ContextInput`.
- `Config`, `ConfigInterface`, the providers (Monolog, PDO, Redis, Symfony Translator), `I18n`,
  and the `monolog/monolog` and `vlucas/phpdotenv` dependencies.
- `Interaction::__destruct()` warning, `DependencyException`, `ConfigException`,
  `InvalidInteractionException`, `StopExecutionException`, `FSMException`.
- `Context::getStateManager()`, `setStateManager()`, `setSession()`, `getSession()`.

## [1.0.2] - 2026-08-30

Last release of the 1.x line.

[Unreleased]: https://github.com/chatflowphp/core/compare/2.0.0...HEAD
[2.0.0]: https://github.com/chatflowphp/core/compare/1.0.2...2.0.0
[1.0.2]: https://github.com/chatflowphp/core/releases/tag/1.0.2
