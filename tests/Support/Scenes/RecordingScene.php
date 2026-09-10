<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support\Scenes;

use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;
use ChatFlow\Tests\Support\HookLog;

/**
 * Base for fixtures that record their lifecycle in the shared HookLog.
 */
abstract class RecordingScene extends BaseScene
{
    public function __construct(protected readonly HookLog $log) {}

    public function onEnter(Context $ctx): void
    {
        $this->log->add($this->name() . ':enter');
        $ctx->reply($this->name() . ' screen');
    }

    public function onLeave(Context $ctx): void
    {
        $this->log->add($this->name() . ':leave');
    }

    public function handle(Context $ctx): void
    {
        $this->log->add($this->name() . ':handle:' . $ctx->getText());
        $ctx->reply($this->name() . ' got ' . $ctx->getText());
    }

    protected function name(): string
    {
        return str_ends_with($this->getTitle(), 'Scene') ? substr($this->getTitle(), 0, -5) : $this->getTitle();
    }
}
