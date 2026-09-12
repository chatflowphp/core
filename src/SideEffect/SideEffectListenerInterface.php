<?php

declare(strict_types=1);

namespace ChatFlow\SideEffect;

use ChatFlow\Core\Context;

/**
 * A scene that wants to hear the result of a side effect. When a handler returns data, the
 * runtime runs a system tick in the conversation and calls this hook on the active scene. The
 * hook may reply, change the session and navigate like any other scene method.
 */
interface SideEffectListenerInterface
{
    /**
     * @param array<string, mixed> $result
     */
    public function onSideEffect(Context $ctx, SideEffect $effect, array $result): void;
}
