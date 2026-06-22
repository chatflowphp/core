<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

/**
 * Exception thrown for missing external dependency errors.
 *
 * This exception is used when the library requires external dependencies
 * that are not available, such as Redis, PDO, Monolog, or other optional
 * components that must be installed separately.
 */
class DependencyException extends BotException
{
}
