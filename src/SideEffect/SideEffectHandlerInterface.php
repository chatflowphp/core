<?php

declare(strict_types=1);

namespace ChatFlow\SideEffect;

/**
 * Executes one kind of side effect. Runs after the tick committed, outside of any conversation
 * lock; may take as long as it needs and may throw, in which case the effect stays pending and is
 * retried on the conversation's next event or on Application::drain().
 *
 * Handlers must be idempotent by SideEffect::$id: the runtime guarantees at-least-once execution.
 */
interface SideEffectHandlerInterface
{
    /**
     * @return array<string, mixed>|null Data to hand back to the conversation, or null when the
     *                                   conversation does not need to know; see SideEffectListenerInterface
     */
    public function handle(SideEffect $effect): ?array;
}
