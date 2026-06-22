<?php

declare(strict_types=1);

namespace ChatFlow\Container;

use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\ServiceNotFoundException;
use DI\Container as DIContainer;
use DI\ContainerBuilder;
use Throwable;

/**
 * Hybrid Dependency Injection Container.
 *
 * Combines PHP-DI for static compilation (performance) and a runtime array
 * for dynamic bindings during request processing.
 */
class Container implements ContainerInterface
{
    private ?DIContainer $container = null;

    /** @var array<string, mixed> */
    private array $runtimeInstances = [];

    /**
     * @param ContainerBuilder<DIContainer>|null $builder
     */
    public function __construct(
        private readonly ?ContainerBuilder $builder = null,
        bool $autowire = true,
        bool $useAttributes = false,
    ) {
        if ($this->builder !== null) {
            $this->builder->useAutowiring($autowire);
            $this->builder->useAttributes($useAttributes);
        }
    }

    /**
     * @throws ContainerException
     */
    public function enableCompilation(string $directory): void
    {
        if ($this->builder === null) {
            throw new ContainerException('Cannot enable compilation: builder is not initialized');
        }
        $this->builder->enableCompilation($directory);
    }

    /**
     * @throws ContainerException if entry is not found or cannot be resolved
     */
    public function get(string $id): mixed
    {
        // 1. Check runtime cache (fast path for dynamic objects like Context)
        if (array_key_exists($id, $this->runtimeInstances)) {
            return $this->runtimeInstances[$id];
        }

        $this->ensureBuilt();

        // 2. Check compiled container
        if (!$this->container->has($id)) {
            throw new ServiceNotFoundException(sprintf('Service "%s" not found', $id));
        }

        try {
            return $this->container->get($id);
        } catch (Throwable $e) {
            if ($e instanceof ContainerException) {
                throw $e;
            }

            throw new ContainerException($e->getMessage(), 0, $e);
        }
    }

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->runtimeInstances)) {
            return true;
        }

        try {
            $this->ensureBuilt();

            return $this->container->has($id);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @throws ContainerException
     */
    public function bind(string $id, mixed $value): void
    {
        if ($this->container === null) {
            // Build-time: Add to definitions
            if ($this->builder === null) {
                throw new ContainerException('Cannot bind: builder is not initialized');
            }
            $this->builder->addDefinitions([
                $id => $value,
            ]);
        } else {
            // Runtime: Add to local cache
            $this->runtimeInstances[$id] = $value;
        }
    }

    /**
     * @throws ContainerException
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
        if ($this->container !== null) {
            throw new ContainerException('Cannot register singletons after container is built');
        }

        if ($this->builder === null) {
            throw new ContainerException('Cannot register singleton: builder is not initialized');
        }

        $concrete ??= $id;

        // In PHP-DI, simple values/closures in definitions act as singletons by default
        $this->builder->addDefinitions([
            $id => $concrete,
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function call(callable $callable, array $parameters = []): mixed
    {
        $this->ensureBuilt();

        try {
            return $this->container->call($callable, $parameters + $this->runtimeInstances);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @throws ContainerException
     */
    public function make(string $className, array $parameters = []): mixed
    {
        $this->ensureBuilt();

        try {
            return $this->container->make($className, $parameters);
        } catch (Throwable $e) {
            if ($e instanceof ContainerException) {
                throw $e;
            }

            throw new ContainerException($e->getMessage(), 0, $e);
        }
    }

    public function flush(): void
    {
        $this->runtimeInstances = [];
    }

    /**
     * @param array<string, mixed> $definitions
     *
     * @throws ContainerException
     */
    public function addDefinitions(array $definitions): void
    {
        if ($this->container !== null) {
            throw new ContainerException('Cannot add definitions after container is built');
        }
        if ($this->builder === null) {
            throw new ContainerException('Cannot add definitions: builder is not initialized');
        }
        $this->builder->addDefinitions($definitions);
    }

    /**
     * @throws ContainerException
     */
    public function getDIContainer(): DIContainer
    {
        $this->ensureBuilt();

        return $this->container;
    }

    /**
     * @phpstan-assert !null $this->container
     *
     * @throws ContainerException
     */
    private function ensureBuilt(): void
    {
        if ($this->container === null) {
            if ($this->builder === null) {
                throw new ContainerException('Cannot build container: builder is not initialized');
            }
            $this->container = $this->builder->build();
        }
    }
}
