<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

/**
 * Exception thrown for storage driver related errors.
 *
 * This exception is used when there are issues with storage operations,
 * such as connection failures to Redis or database, file system errors,
 * serialization problems, or other storage-related issues.
 */
class StorageException extends BotException
{
}
