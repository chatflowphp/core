# Long Turns

A tick is short: it must hold the conversation for milliseconds, and it must be safe to roll
back. Some replies need seconds: a language model, a slow API, a report. The runtime handles
that with two ticks and the work in between.

```text
tick 1   record the input, schedule the work            (milliseconds, under the lock)
work     side effect handler: model call, API, report   (as long as it takes, no lock)
tick 2   system tick with the result: reply, update     (milliseconds, under the lock)
```

## The Pattern

```php
final class AssistantScene extends BaseScene implements SideEffectListenerInterface
{
    public function __construct(private readonly StreamStorageInterface $streams) {}

    public function handle(Context $ctx): void
    {
        $seq = $this->streams->append($ctx->getConversationId() . ':inbox', ['text' => $ctx->getText()]);
        $ctx->session()->set('inbox_seq', $seq);

        if ($ctx->session()->get('thinking') !== true) {
            $ctx->session()->set('thinking', true);
            $ctx->schedule('answer', ['upto' => $seq]);
        }
    }

    public function onSideEffect(Context $ctx, SideEffect $effect, array $result): void
    {
        $ctx->reply($result['text']);

        if ($result['upto'] === $ctx->session()->get('inbox_seq')) {
            $ctx->session()->set('thinking', false);

            return;
        }

        $ctx->schedule('answer', ['upto' => $ctx->session()->get('inbox_seq')]);   // catch up
    }
}
```

The handler registered as `answer` reads the inbox stream up to `upto`, calls the model and
returns `['upto' => ..., 'text' => ...]`.

What this gives:

- **Nothing is lost.** Every message is in the stream before anything slow starts. A crash
  during the model call leaves the side effect pending; the next event or `drain()` runs it
  again.
- **One generation at a time.** The `thinking` flag lives in the session and is set inside the
  tick, so a second message while the model works only lands in the inbox.
- **Messages that arrived meanwhile are answered**, not skipped: the result carries the
  position it was computed for, and the listener schedules one more round when the inbox moved.
- **Idempotent by design.** The effect id is stable; a repeated execution produces the same
  answer for the same `upto`.

Flags in the session need a way out when a handler dies for good: after the configured attempts
the effect moves to the failed list, and the scene should treat a `thinking` flag older than a
few minutes as stale.

## Workers Outside The Runtime

Work that does not go through side effects, such as a job in another process, applies its result
with `run()` and the tick it was computed for:

```php
// inside the tick that started the job
$job->dispatch(conversation: $ctx->getConversationId(), tick: $ctx->getTickCount());

// in the worker, when the job is done
$result = $application->run($conversationId, static function (Context $ctx) use ($answer): void {
    $ctx->reply($answer);
}, 'answer', expectedTick: $tick);

if ($result->getMessage() === 'conversation_moved') {
    // something else changed the conversation; recompute or drop
}
```

`Context::getTickCount()` is the number the current tick commits as. `run()` with
`expectedTick` runs its handler only when the conversation is still at that tick and returns
`conversation_moved` otherwise, without retrying.

## No Special Scene Type

A scene used this way never calls `ask()` and defines no `on*` action methods, so every input
reaches `handle()`; override `onEnter()` when the default (which calls `handle()`) is not wanted.
Global routes such as commands still interrupt it unless `allowsGlobalRoutes()` returns `false`;
system ticks always reach it.
