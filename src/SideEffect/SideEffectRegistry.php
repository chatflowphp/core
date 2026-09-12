<?php

declare(strict_types=1);

namespace ChatFlow\SideEffect;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\SideEffectException;
use ChatFlow\Support\SerializableValueValidator;
use Closure;

/**
 * Maps handler names used in Context::schedule() to their implementations. A handler is an
 * instance, a class name resolved through the container, or a closure with the handler's
 * signature.
 */
final class SideEffectRegistry
{
    /**
     * @var array<string, SideEffectHandlerInterface|class-string<SideEffectHandlerInterface>|Closure>
     */
    private array $handlers = [];

    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * @param SideEffectHandlerInterface|class-string<SideEffectHandlerInterface>|Closure(SideEffect): (array<string, mixed>|null) $handler
     */
    public function register(string $name, SideEffectHandlerInterface|string|Closure $handler): self
    {
        if ($name === '') {
            throw new SideEffectException('A side effect handler needs a non-empty name.');
        }

        $this->handlers[$name] = $handler;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * @throws SideEffectException When no handler is registered under the name.
     */
    public function resolve(string $name): SideEffectHandlerInterface
    {
        $handler = $this->handlers[$name] ?? throw new SideEffectException(\sprintf('No side effect handler is registered as "%s".', $name));

        if ($handler instanceof SideEffectHandlerInterface) {
            return $handler;
        }

        if ($handler instanceof Closure) {
            return new class ($handler) implements SideEffectHandlerInterface {
                public function __construct(private readonly Closure $closure) {}

                public function handle(SideEffect $effect): ?array
                {
                    $result = ($this->closure)($effect);

                    return \is_array($result) ? SerializableValueValidator::normalizeMap($result, 'result') : null;
                }
            };
        }

        $instance = $this->container->make($handler);

        if (!$instance instanceof SideEffectHandlerInterface) {
            throw new SideEffectException(\sprintf(
                'Side effect handler "%s" resolved to %s, which does not implement %s.',
                $name,
                get_debug_type($instance),
                SideEffectHandlerInterface::class,
            ));
        }

        return $instance;
    }
}
