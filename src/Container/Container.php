<?php

declare(strict_types=1);

namespace ChatFlow\Container;

use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\ServiceNotFoundException;
use DI\Container as DIContainer;
use DI\ContainerBuilder;
use Invoker\Exception\NotCallableException;
use Invoker\Exception\NotEnoughParametersException;
use Invoker\Invoker;
use Invoker\ParameterResolver\AssociativeArrayResolver;
use Invoker\ParameterResolver\Container\TypeHintContainerResolver;
use Invoker\ParameterResolver\DefaultValueResolver;
use Invoker\ParameterResolver\NumericArrayResolver;
use Invoker\ParameterResolver\ResolverChain;
use Invoker\ParameterResolver\TypeHintResolver;
use Throwable;

use function DI\autowire;
use function DI\factory;

/**
 * PHP-DI backed container. Definitions are collected until the first lookup builds the
 * underlying container; afterwards set() keeps instances for the container lifetime and scoped()
 * keeps them until flush().
 *
 * call() resolves parameters from the given overrides (by type or by name), then from this
 * container (scoped and persistent instances first, then PHP-DI), then from default values.
 */
class Container implements ContainerInterface
{
    private ?DIContainer $container = null;

    private ?Invoker $invoker = null;

    /**
     * @var array<string, mixed>
     */
    private array $persistent = [];

    /**
     * @var array<string, mixed>
     */
    private array $scoped = [];

    /**
     * @param ContainerBuilder<DIContainer>|null $builder
     */
    public function __construct(
        private ?ContainerBuilder $builder = null,
        bool $autowire = true,
        bool $useAttributes = false,
    ) {
        if ($this->builder === null) {
            $this->builder = new ContainerBuilder();
        }

        $this->builder->useAutowiring($autowire);
        $this->builder->useAttributes($useAttributes);
        $this->builder->addDefinitions([
            ContainerInterface::class => $this,
            self::class => $this,
        ]);
    }

    /**
     * @throws ContainerException
     */
    public function enableCompilation(string $directory): void
    {
        $this->builder()->enableCompilation($directory);
    }

    /**
     * @param array<string, mixed> $definitions
     *
     * @throws ContainerException
     */
    public function addDefinitions(array $definitions): void
    {
        $this->builder()->addDefinitions($definitions);
    }

    public function get(string $id): mixed
    {
        if (\array_key_exists($id, $this->scoped)) {
            return $this->scoped[$id];
        }

        if (\array_key_exists($id, $this->persistent)) {
            return $this->persistent[$id];
        }

        $container = $this->built();

        if (!$container->has($id)) {
            throw new ServiceNotFoundException(\sprintf('Service "%s" not found', $id));
        }

        try {
            return $container->get($id);
        } catch (Throwable $e) {
            throw new ContainerException($e->getMessage(), 0, $e);
        }
    }

    public function has(string $id): bool
    {
        if (\array_key_exists($id, $this->scoped) || \array_key_exists($id, $this->persistent)) {
            return true;
        }

        try {
            return $this->built()->has($id);
        } catch (Throwable) {
            return false;
        }
    }

    public function set(string $id, mixed $value): void
    {
        if ($this->container === null) {
            $this->builder()->addDefinitions([$id => $value]);

            return;
        }

        $this->persistent[$id] = $value;
    }

    public function scoped(string $id, mixed $value): void
    {
        $this->scoped[$id] = $value;
    }

    public function singleton(string $id, string|callable|null $concrete = null): void
    {
        if ($this->container !== null) {
            throw new ContainerException('Cannot register singletons after the container is built; use set() with an instance instead.');
        }

        $concrete ??= $id;

        if (\is_callable($concrete)) {
            $this->builder()->addDefinitions([$id => factory($concrete)]);

            return;
        }

        $this->builder()->addDefinitions([$id => autowire($concrete)]);
    }

    public function call(callable $callable, array $parameters = []): mixed
    {
        $this->built();

        try {
            return $this->invoker()->call($callable, $parameters + $this->scoped + $this->persistent);
        } catch (NotCallableException|NotEnoughParametersException $e) {
            throw new ContainerException($e->getMessage(), 0, $e);
        }
    }

    public function make(string $className, array $parameters = []): mixed
    {
        try {
            return $this->built()->make($className, $parameters);
        } catch (ContainerException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ContainerException($e->getMessage(), 0, $e);
        }
    }

    public function flush(): void
    {
        $this->scoped = [];
    }

    /**
     * @throws ContainerException
     */
    public function getDIContainer(): DIContainer
    {
        return $this->built();
    }

    private function invoker(): Invoker
    {
        return $this->invoker ??= new Invoker(new ResolverChain([
            new TypeHintResolver(),
            new AssociativeArrayResolver(),
            new NumericArrayResolver(),
            new TypeHintContainerResolver($this),
            new DefaultValueResolver(),
        ]), $this);
    }

    /**
     * @return ContainerBuilder<DIContainer>
     *
     * @throws ContainerException
     */
    private function builder(): ContainerBuilder
    {
        if ($this->container !== null) {
            throw new ContainerException('Cannot add definitions after the container is built.');
        }

        return $this->builder ?? throw new ContainerException('Container builder is not available.');
    }

    /**
     * @throws ContainerException
     */
    private function built(): DIContainer
    {
        if ($this->container === null) {
            try {
                $this->container = ($this->builder ?? throw new ContainerException('Container builder is not available.'))->build();
            } catch (ContainerException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new ContainerException($e->getMessage(), 0, $e);
            }
        }

        return $this->container;
    }
}
