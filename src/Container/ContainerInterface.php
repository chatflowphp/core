<?php

declare(strict_types=1);

namespace ChatFlow\Container;

use ChatFlow\Exception\ContainerException;
use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Extended container interface supporting service registration and dependency injection methods.
 *
 * This interface decouples the framework from the concrete DI implementation.
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Register a binding with the container.
     *
     * @param string $id    Service identifier (usually class name or interface)
     * @param mixed  $value The value or closure to bind
     *
     * @throws ContainerException
     */
    public function bind(string $id, mixed $value): void;

    /**
     * Set a service in the container (alias for bind).
     *
     * @param string $id    Service identifier
     * @param mixed  $value The value or closure to bind
     *
     * @throws ContainerException
     */
    public function set(string $id, mixed $value): void;

    /**
     * Register a shared binding (singleton) in the container.
     *
     * @param string               $id       Service identifier
     * @param string|callable|null $concrete Concrete class or closure (optional)
     *
     * @throws ContainerException
     */
    public function singleton(string $id, string|callable|null $concrete = null): void;

    /**
     * Call the given callback and inject dependencies.
     *
     * @param callable             $callable   Function or method to call
     * @param array<string, mixed> $parameters Custom parameters to pass
     *
     * @return mixed The result of the callback
     *
     * @throws ContainerException
     */
    public function call(callable $callable, array $parameters = []): mixed;

    /**
     * Resolve the given type from the container.
     *
     * @param string               $className  Class name to instantiate
     * @param array<string, mixed> $parameters Custom parameters to pass to the constructor
     *
     * @return mixed The instantiated object
     *
     * @throws ContainerException
     */
    public function make(string $className, array $parameters = []): mixed;

    /**
     * Check if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for
     *
     * @return bool
     */
    public function has(string $id): bool;

    /**
     * Flush runtime bindings.
     *
     * Should be called after every request/update cycle in long-polling mode
     * to prevent state leakage between updates.
     */
    public function flush(): void;
}
