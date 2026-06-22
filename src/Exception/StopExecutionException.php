<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use Throwable;

class StopExecutionException extends BotException
{
    public function __construct(
        string $message = 'Execution stopped',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
