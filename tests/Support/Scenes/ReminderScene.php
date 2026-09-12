<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support\Scenes;

use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;
use ChatFlow\Tests\Support\HookLog;
use ChatFlow\Timer\Timer;
use ChatFlow\Timer\TimerListenerInterface;

/**
 * Arms a silence timer on every message and reacts when it fires.
 */
final class ReminderScene extends BaseScene implements TimerListenerInterface
{
    public function __construct(private readonly HookLog $log) {}

    public function getId(): string
    {
        return 'reminder';
    }

    public function onEnter(Context $ctx): void
    {
        $ctx->reply('Talk to me');
    }

    public function handle(Context $ctx): void
    {
        if ($ctx->getText() === 'stop') {
            $ctx->cancelTimer('silence');
            $ctx->reply('Timer off');

            return;
        }

        $ctx->wakeAt(3600, 'silence', ['last' => $ctx->getText()]);
        $ctx->reply('Noted at ' . $ctx->getOccurredAt()->format('H:i'));
    }

    public function onTimer(Context $ctx, Timer $timer): void
    {
        $this->log->add('Reminder:timer:' . $timer->reason . ':' . json_encode($timer->payload, JSON_THROW_ON_ERROR));
        $ctx->session()->increment('nudges');
        $ctx->reply('Still there?');
        $ctx->leave();
    }
}
