<?php

declare(strict_types=1);

namespace ChatFlow\Timer;

use ChatFlow\Core\Context;

/**
 * A scene that wants to be woken by its timers. Application::runDue() runs a system tick in the
 * conversation and calls this hook on the active scene; it may reply, change the session and
 * navigate like any other scene method.
 */
interface TimerListenerInterface
{
    public function onTimer(Context $ctx, Timer $timer): void;
}
