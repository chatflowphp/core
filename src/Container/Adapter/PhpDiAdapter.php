<?php

declare(strict_types=1);

namespace ChatFlow\Container\Adapter;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\ServiceNotFoundException;
use DI\Container as ExternalContainer;
use DI\DependencyException;
use DI\NotFoundException;
use Throwable;

/**
 * Adapter for an existing PHP-DI Container instance.
 *
 * This adapter allows integrating ChatFlow into an application that already
 * uses PHP-DI as its dependency injection container.
 * It provides a layer to handle runtime bindings and flushing, which are
 * required for the bot's stateful operations (e.g., long-polling).
 */
class PhpDiAdapter implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $runtimeInstances = [];

    public function __construct(
        private readonly ExternalContainer $container
    ) {
    }

    /**
     * @throws ContainerException if entry cannot be resolved
     */
    public function get(string $id): mixed
    {
        // 1. Check runtime bindings first (fast path)
        if (array_key_exists($id, $this->runtimeInstances)) {
            return $this->runtimeInstances[$id];
        }

        // 2. Delegate to external container
        try {
            return $this->container->get($id);
        } catch (NotFoundException $e) {
            throw new ServiceNotFoundException($e->getMessage(), (int) $e->getCode(), $e);
        } catch (DependencyException $e) {
            throw new ContainerException($e->getMessage(), (int) $e->getCode(), $e);
        } catch (Throwable $e) {
            throw new ContainerException('Error resolving service: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->runtimeInstances)) {
            return true;
        }

        return $this->container->has($id);
    }

    public function bind(string $id, mixed $value): void
    {
        $this->runtimeInstances[$id] = $value;
    }

    /**
     * Alias for bind.
     */
    public function set(string $id, mixed $value): void
    {
        $this->bind($id, $value);
    }

    /**
     * @throws ContainerException
     */
    public function singleton(string $id, string|callable|null $concrete = null): void
    {
        // Since the external container is likely already compiled/built,
        // we cannot add definitions to it safely.
        // We treat singletons here as persistent runtime bindings.
        // Note: These will be cleared if flush() is called, so they behave
        // scoped to the bot's lifecycle loop in this adapter context.

        $concrete ??= $id;

        // If it's a callable factory, resolve it immediately and bind the result
        if (is_callable($concrete) && !is_string($concrete)) {
            /** @var mixed $value */
            $value = $concrete($this);
            $this->bind($id, $value);

            return;
        }

        // If it's a class string, instantiate it
        if (class_exists($concrete)) {
            $instance = $this->make($concrete);
            $this->bind($id, $instance);

            return;
        }

        // Otherwise bind as value
        $this->bind($id, $concrete);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws ContainerException if callable cannot be invoked
     */
    public function call(callable $callable, array $parameters = []): mixed
    {
        try {
            return $this->container->call($callable, $parameters);
        } catch (Throwable $e) {
            throw new ContainerException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws ContainerException if class cannot be instantiated
     */
    public function make(string $className, array $parameters = []): mixed
    {
        try {
            return $this->container->make($className, $parameters);
        } catch (Throwable $e) {
            throw new ContainerException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Clears runtime bindings.
     *
     * Important for preventing memory leaks and state pollution in long-polling mode.
     */
    public function flush(): void
    {
        $this->runtimeInstances = [];
    }
}
