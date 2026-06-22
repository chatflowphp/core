<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use Throwable;

class InvalidInteractionException extends BotException
{
    public function __construct(
        string $message = 'Invalid interaction state',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
