# Architecture

A conversation is a state machine from `chatflowphp/automata`:

- **states** are scenes, plus the built-in root scene where routes run;
- **context** is the conversation data (`SceneContext`), including scene history and the pending
  interaction;
- **input** is the inbound event plus the route matched for it;
- **a tick** is the processing of one inbound event;
- **transitions** are `enter()`, `back()` and `leave()` calls made inside the tick;
- **the snapshot** is what gets stored between updates.

## Pipeline

For every inbound event, `Application::handle()`:

1. creates a `Context` and binds it into the container's request scope;
2. resumes the conversation: loads the snapshot, or starts the machine in the root scene;
3. applies a transition scheduled with `enterLater()`/`leaveLater()` as its own transaction,
   persists and delivers its messages, and stops here unless the event should be handled too;
4. matches a route (adapters may pass one explicitly);
5. records the target for observability;
6. builds the middleware stack: global middleware, the active scene's middleware, and the route's
   middleware when the route will run;
7. runs the pipeline around one machine tick;
8. persists the snapshot when the tick ran and the conversation has something worth storing;
9. delivers the queued outbound effects through the adapter, in order;
10. runs the side effects the conversation has pending, each against a fresh snapshot, and hands
    their results back as system ticks;
11. runs the adapter's `afterHandle()` hook, if it has one, and flushes the container's request
    scope.

If anything throws before step 9, the machine has already rolled back its state and context, the
effect queue is cleared, and the error handler is the only code that may reply. A failed button
press is acknowledged as an alert by the default error handler, so the client stops waiting.

## What Happens Inside The Tick

The machine hands the input to the current state:

- **Root scene**: runs the matched route handler, or reports "no route".
- **A scene**: if the route is global and the scene allows it, runs the route; otherwise handles a
  scene action, a pending interaction, or falls back to `handle()`.

Transitions requested during the tick run immediately: `onLeave()` of the current scene, then
`onEnter()` of the target, all inside the same operation. Chains are limited to 32 transitions
per tick.

## Boundaries

Core owns the event model, routing, middleware order, scene lifecycle, session mutation, the view
model, the effect queue, side effects, capability enforcement and runtime events.

Adapters own platform input parsing, delivery to remote APIs, file downloads, capability
declaration, platform-specific helpers and error mapping around vendor SDKs.

## Data Flow

```text
platform update
  -> adapter createInboundEvent()
  -> Application handle()
  -> Context
  -> ConversationManager resume()
  -> middleware
  -> StateMachine tick()  (root: route handler / scene: action, interaction, handle)
  -> Context reply/render/ack  (queued)
  -> persist snapshot
  -> adapter deliver()
  -> side effect handlers  (stored with the snapshot, executed after it)
```

## Runtime Dependency Binding

`Application` binds `Context` and `InboundEventInterface` into the request scope of the
container. Adapters implementing `RuntimeDependencyBinderInterface` add their own request-scoped
services (the Telegram adapter binds `TelegramContext`). Handlers receive them by type hint or by
parameter name.

## Capability Enforcement

`Context` checks `PlatformCapabilities` before queueing an effect: `render()` requires screen
rendering, `ack()` requires acknowledgements, actions, choices and media require the matching
capability, `downloadAttachment()` requires attachment download. Unsupported features fail with
`UnsupportedCapabilityException` before anything is sent.
