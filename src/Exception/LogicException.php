<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

/**
 * Exception thrown for logic-related errors.
 *
 * This exception is used when there are issues with the application logic,
 * such as invalid method calls in the current context, inconsistent state,
 * or other programming logic errors that should not occur in normal operation.
 */
class LogicException extends BotException {}
