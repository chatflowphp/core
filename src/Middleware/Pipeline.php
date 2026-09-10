<?php

declare(strict_types=1);

namespace ChatFlow\Middleware;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Core\Context;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\LogicException;

/**
 * Onion-style middleware pipeline around a destination callable.
 */
class Pipeline
{
    /**
     * @var list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    private array $middlewares = [];

    private ?Context $context = null;

    public function __construct(private readonly ?ContainerInterface $container = null) {}

    public function send(Context $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     */
    public function through(array $middlewares): self
    {
        $this->middlewares = $middlewares;

        return $this;
    }

    /**
     * @param callable(Context): mixed $destination
     *
     * @throws LogicException When no context was sent.
     * @throws ContainerException When a middleware cannot be resolved.
     */
    public function then(callable $destination): mixed
    {
        $context = $this->context ?? throw new LogicException('Context must be set before running the pipeline.');

        $pipeline = $destination;

        foreach (array_reverse($this->middlewares) as $middleware) {
            $instance = $this->resolve($middleware);
            $next = $pipeline;
            $pipeline = static fn(Context $ctx): mixed => $instance->process($ctx, $next);
        }

        return $pipeline($context);
    }

    /**
     * @param MiddlewareInterface|class-string<MiddlewareInterface> $middleware
     *
     * @throws ContainerException
     */
    private function resolve(MiddlewareInterface|string $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        $instance = $this->container !== null && $this->container->has($middleware)
            ? $this->container->get($middleware)
            : ($this->container?->make($middleware) ?? (class_exists($middleware) ? new $middleware() : null));

        if (!$instance instanceof MiddlewareInterface) {
            throw new ContainerException(\sprintf('Unable to resolve middleware "%s".', $middleware));
        }

        return $instance;
    }
}
