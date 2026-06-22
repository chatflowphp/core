<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

/**
 * Exception thrown for config-related errors.
 *
 * This exception is used when there are issues with the library config,
 * such as missing required config values, invalid config options,
 * or failures during config loading.
 */
class ConfigException extends BotException
{
}
