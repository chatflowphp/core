<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use Throwable;

class SceneNotFoundException extends BotException
{
    public function __construct(
        string $sceneClass,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $message = sprintf('Scene "%s" not found in registry', $sceneClass);
        parent::__construct($message, $code, $previous);
    }
}
