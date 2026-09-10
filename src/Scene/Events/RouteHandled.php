<?php

declare(strict_types=1);

namespace ChatFlow\Scene\Events;

use Automata\Messaging\EventInterface;

/**
 * Emitted by a tick when a route handler ran, either in the root scene or as a global route
 * interrupting an active scene.
 */
final class RouteHandled implements EventInterface
{
    public function __construct(
        public readonly string $type,
        public readonly string $pattern,
        public readonly bool $global,
        public readonly string $sceneId,
    ) {}
}
