<?php

declare(strict_types=1);

namespace ChatFlow\Container;

use ChatFlow\Exception\ContainerException;
use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Container contract used by the runtime: PSR-11 lookup plus registration, invocation and a
 * request scope that is flushed after every handled event.
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Registers a value or an instance for the lifetime of the container.
     *
     * @throws ContainerException
     */
    public function set(string $id, mixed $value): void;

    /**
     * Registers a value or an instance for the current request only. Cleared by flush().
     */
    public function scoped(string $id, mixed $value): void;

    /**
     * Registers a shared service built from a class name or a factory.
     *
     * @throws ContainerException
     */
    public function singleton(string $id, string|callable|null $concrete = null): void;

    /**
     * Calls the callable, resolving parameters by type, by name and from the given overrides.
     *
     * @param array<string, mixed> $parameters
     *
     * @throws ContainerException
     */
    public function call(callable $callable, array $parameters = []): mixed;

    /**
     * Builds a new instance, applying container definitions for the class.
     *
     * @param array<string, mixed> $parameters
     *
     * @throws ContainerException
     */
    public function make(string $className, array $parameters = []): mixed;

    public function has(string $id): bool;

    /**
     * Clears request-scoped registrations. Called by the runtime after each handled event.
     */
    public function flush(): void;
}
