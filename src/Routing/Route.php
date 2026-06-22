<?php

declare(strict_types=1);

namespace ChatFlow\Routing;

use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Middleware\MiddlewareInterface;

final class Route
{
    /** @var array<MiddlewareInterface|class-string<MiddlewareInterface>> */
    private array $middlewares = [];

    public function __construct(
        private readonly string $type,
        private readonly string $prefix,
        private readonly mixed $handler,
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getHandler(): callable
    {
        return $this->handler;
    }

    public function matches(InboundEventInterface $event): bool
    {
        if ($this->type === 'fallback') {
            return true;
        }

        if ($this->type === 'command') {
            $command = '/' . ltrim($this->prefix, '/');
            $text = $event->getText();

            return $text === $command || str_starts_with($text, $command . ' ');
        }

        if ($this->type === 'text_regex') {
            return @preg_match($this->prefix, $event->getText()) === 1;
        }

        if ($this->type === 'action_regex') {
            return @preg_match($this->prefix, $event->getActionId() ?? '') === 1;
        }

        if ($this->type === 'action') {
            return ($event->getActionId() ?? '') === $this->prefix;
        }

        $candidate = str_starts_with($this->type, 'action')
            ? ($event->getActionId() ?? '')
            : $event->getText();

        return str_starts_with($candidate, $this->prefix);
    }

    /**
     * @param MiddlewareInterface|class-string<MiddlewareInterface>|array<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     */
    public function middleware(MiddlewareInterface|string|array $middleware): self
    {
        if (is_array($middleware)) {
            $this->middlewares = [...$this->middlewares, ...$middleware];
        } else {
            $this->middlewares[] = $middleware;
        }

        return $this;
    }

    /**
     * @return array<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
}
