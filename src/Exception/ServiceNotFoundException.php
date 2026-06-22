<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Exception thrown when a requested service is not found in the container.
 *
 * This exception is used when attempting to retrieve a service from the DI
 * container that has not been registered or does not exist. It implements
 * PSR-11 NotFoundExceptionInterface for compatibility with PSR-11 compliant
 * containers.
 */
class ServiceNotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
