<?php

declare(strict_types=1);

namespace ChatFlow\Scene\Events;

use Automata\Messaging\EventInterface;

/**
 * Emitted by a tick when the active scene consumed the inbound event.
 */
final class SceneHandled implements EventInterface
{
    public const ACTION = 'action';
    public const INTERACTION = 'interaction';
    public const HANDLE = 'handle';

    /**
     * @param self::ACTION|self::INTERACTION|self::HANDLE $kind
     */
    public function __construct(
        public readonly string $sceneId,
        public readonly string $kind,
    ) {}
}
