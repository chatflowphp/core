<?php

declare(strict_types=1);

namespace ChatFlow\FSM\Interop;

use Automata\Contracts\InputInterface;
use ChatFlow\Core\Context;

final class ContextInput implements InputInterface
{
    public function __construct(
        private readonly Context $context
    ) {
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
