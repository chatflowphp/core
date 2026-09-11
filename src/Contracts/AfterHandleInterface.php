<?php

declare(strict_types=1);

namespace ChatFlow\Contracts;

use ChatFlow\Core\Context;
use ChatFlow\Core\Result;

/**
 * Optional adapter hook that runs after every handled event, once the queued effects were
 * delivered (or dropped). Adapters use it for platform housekeeping that must happen even when
 * the handler failed, for example answering a callback query nobody acknowledged.
 */
interface AfterHandleInterface
{
    public function afterHandle(Context $context, Result $result): void;
}
