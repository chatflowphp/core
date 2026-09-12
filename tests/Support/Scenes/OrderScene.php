<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support\Scenes;

use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;
use ChatFlow\SideEffect\SideEffect;
use ChatFlow\SideEffect\SideEffectListenerInterface;
use ChatFlow\Tests\Support\HookLog;

/**
 * Schedules a side effect on any text and hears its result back.
 */
final class OrderScene extends BaseScene implements SideEffectListenerInterface
{
    public function __construct(private readonly HookLog $log) {}

    public function getId(): string
    {
        return 'order';
    }

    public function allowsGlobalRoutes(): bool
    {
        return false;
    }

    public function onEnter(Context $ctx): void
    {
        $ctx->reply('Amount?');
    }

    public function handle(Context $ctx): void
    {
        $effect = $ctx->schedule('charge', ['amount' => (int) $ctx->getText()]);
        $this->log->add('Order:scheduled:' . $effect->id);
        $ctx->reply('Charging ' . $ctx->getText());
    }

    public function onSideEffect(Context $ctx, SideEffect $effect, array $result): void
    {
        $this->log->add('Order:result:' . $effect->handler . ':' . json_encode($result, JSON_THROW_ON_ERROR));
        $ctx->session()->set('receipt', $result['receipt'] ?? null);
        $ctx->reply('Charged, receipt ' . (\is_string($result['receipt'] ?? null) ? $result['receipt'] : '?'));

        if (($result['final'] ?? false) === true) {
            $ctx->leave();
        }
    }
}
