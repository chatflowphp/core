<?php

declare(strict_types=1);

namespace ChatFlow\Scene\Events;

use Automata\Messaging\EventInterface;

/**
 * Emitted by the root scene when no route matched the inbound event.
 */
final class RouteMissed implements EventInterface {}
