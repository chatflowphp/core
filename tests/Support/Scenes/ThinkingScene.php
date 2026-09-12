<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support\Scenes;

use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;
use ChatFlow\SideEffect\SideEffect;
use ChatFlow\SideEffect\SideEffectListenerInterface;
use ChatFlow\Storage\StreamStorageInterface;

/**
 * The long-turn pattern: tick 1 records the input and schedules the slow work; the work runs
 * after the commit; its result comes back in a system tick that checks nothing newer arrived.
 */
final class ThinkingScene extends BaseScene implements SideEffectListenerInterface
{
    public function __construct(private readonly StreamStorageInterface $streams) {}

    public function getId(): string
    {
        return 'thinking';
    }

    public function onEnter(Context $ctx): void {}

    public function handle(Context $ctx): void
    {
        $seq = $this->streams->append($ctx->getConversationId() . ':inbox', ['text' => $ctx->getText()]);
        $ctx->session()->set('inbox_seq', $seq);

        if ($ctx->session()->get('thinking') !== true) {
            $ctx->session()->set('thinking', true);
            $ctx->schedule('think', ['upto' => $seq]);
        }
    }

    public function onSideEffect(Context $ctx, SideEffect $effect, array $result): void
    {
        $ctx->reply(\is_string($result['answer'] ?? null) ? $result['answer'] : '?');

        if ($result['upto'] === $ctx->session()->get('inbox_seq')) {
            $ctx->session()->set('thinking', false);

            return;
        }

        // Messages arrived while we were thinking: answer them in one more round.
        $ctx->schedule('think', ['upto' => $ctx->session()->get('inbox_seq')]);
    }
}
