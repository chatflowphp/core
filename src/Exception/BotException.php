<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use RuntimeException;

/**
 * Base exception class for all ChatFlow-related errors.
 */
class BotException extends RuntimeException implements ChatFlowException
{
}
