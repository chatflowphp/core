<?php

declare(strict_types=1);

namespace ChatFlow\Middleware;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Core\Context;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\LogicException;

class Pipeline
{
    /** @var array<MiddlewareInterface|class-string<MiddlewareInterface>> */
    private array $middlewares = [];

    private ?Context $context = null;

    public function __construct(private ?ContainerInterface $container = null)
    {
    }

    public function send(Context $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * @param array<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     */
    public function through(array $middlewares): self
    {
        $this->middlewares = $middlewares;

        return $this;
    }

    /**
     * Execute the pipeline with the given destination.
     *
     * @param callable $destination The final destination callback
     *
     * @return mixed The result of the pipeline execution
     *
     * @throws LogicException     If context is not set before running pipeline
     * @throws ContainerException
     */
    public function then(callable $destination): mixed
    {
        if ($this->context === null) {
            throw new LogicException('Context must be set before running pipeline');
        }

        $pipeline = $this->buildPipeline($destination);

        return $pipeline($this->context);
    }

    /**
     * @throws ContainerException
     */
    private function buildPipeline(callable $destination): callable
    {
        $pipeline = $destination;

        foreach (array_reverse($this->middlewares) as $middleware) {
            $middlewareInstance = $this->resolveMiddleware($middleware);
            $pipeline = function (Context $context) use ($middlewareInstance, $pipeline) {
                return $middlewareInstance->process($context, $pipeline);
            };
        }

        return $pipeline;
    }

    /**
     * Resolve middleware to an instance.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> $middleware The middleware to resolve
     *
     * @return MiddlewareInterface The resolved middleware instance
     *
     * @throws ContainerException If unable to resolve middleware
     */
    private function resolveMiddleware(mixed $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if (is_string($middleware)) {
            if ($this->container !== null && $this->container->has($middleware)) {
                /** @var MiddlewareInterface $instance */
                $instance = $this->container->get($middleware);

                return $instance;
            }

            if (class_exists($middleware)) {
                /** @var MiddlewareInterface $instance */
                $instance = new $middleware();

                return $instance;
            }
        }

        throw new ContainerException('Unable to resolve middleware: ' . var_export($middleware, true));
    }
}
