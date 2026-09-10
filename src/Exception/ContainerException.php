<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use Psr\Container\ContainerExceptionInterface;

/**
 * Exception thrown for dependency injection container errors.
 *
 * This exception is used when there are issues with the DI container,
 * such as circular dependencies, instantiation failures, or other
 * container-related problems. It implements PSR-11 ContainerExceptionInterface
 * for compatibility with PSR-11 compliant containers.
 */
class ContainerException extends BotException implements ContainerExceptionInterface {}
