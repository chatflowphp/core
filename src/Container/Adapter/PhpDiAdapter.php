<?php

declare(strict_types=1);

namespace ChatFlow\Container\Adapter;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\ServiceNotFoundException;
use DI\Container as ExternalContainer;
use DI\NotFoundException;
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

/**
 * Adapter for an application that already owns a built PHP-DI container.
 */
class PhpDiAdapter implements ContainerInterface
{
    private ?Invoker $invoker = null;

    /**
     * @var array<string, mixed>
     */
    private array $persistent = [];

    /**
     * @var array<string, mixed>
     */
    private array $scoped = [];

    public function __construct(private readonly ExternalContainer $container)
    {
        $this->persistent[ContainerInterface::class] = $this;
        $this->persistent[self::class] = $this;
    }

    public function get(string $id): mixed
    {
        if (\array_key_exists($id, $this->scoped)) {
            return $this->scoped[$id];
        }

        if (\array_key_exists($id, $this->persistent)) {
            return $this->persistent[$id];
        }

        try {
            return $this->container->get($id);
        } catch (NotFoundException $e) {
            throw new ServiceNotFoundException($e->getMessage(), $e->getCode(), $e);
        } catch (Throwable $e) {
            throw new ContainerException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->scoped)
            || \array_key_exists($id, $this->persistent)
            || $this->container->has($id);
    }

    public function set(string $id, mixed $value): void
    {
        $this->persistent[$id] = $value;
    }

    public function scoped(string $id, mixed $value): void
    {
        $this->scoped[$id] = $value;
    }

    public function singleton(string $id, string|callable|null $concrete = null): void
    {
        $concrete ??= $id;

        if (!\is_string($concrete)) {
            $this->persistent[$id] = $concrete($this);

            return;
        }

        $this->persistent[$id] = class_exists($concrete) ? $this->make($concrete) : $concrete;
    }

    public function call(callable $callable, array $parameters = []): mixed
    {
        try {
            return $this->invoker()->call($callable, $parameters + $this->scoped + $this->persistent);
        } catch (NotCallableException|NotEnoughParametersException $e) {
            throw new ContainerException($e->getMessage(), 0, $e);
        }
    }

    public function make(string $className, array $parameters = []): mixed
    {
        try {
            return $this->container->make($className, $parameters);
        } catch (Throwable $e) {
            throw new ContainerException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function flush(): void
    {
        $this->scoped = [];
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
}
