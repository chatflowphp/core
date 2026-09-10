<?php

declare(strict_types=1);

namespace ChatFlow\Routing;

use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Middleware\MiddlewareInterface;
use Closure;

/**
 * One routing rule. Routes run in the root scene; global routes (commands by default) also
 * interrupt an active scene unless the scene opts out.
 */
final class Route
{
    public const COMMAND = 'command';
    public const TEXT_PREFIX = 'text_prefix';
    public const TEXT_REGEX = 'text_regex';
    public const ACTION = 'action';
    public const ACTION_PREFIX = 'action_prefix';
    public const ACTION_REGEX = 'action_regex';
    public const FALLBACK = 'fallback';
    public const CUSTOM = 'custom';

    private readonly Closure $handler;

    private bool $global;

    /**
     * @var list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    private array $middlewares = [];

    public function __construct(
        private readonly string $type,
        private readonly string $pattern,
        callable $handler,
        ?bool $global = null,
    ) {
        $this->handler = $handler(...);
        $this->global = $global ?? $type === self::COMMAND;
    }

    /**
     * A route that matches every event; used by adapters to run a handler chosen outside the router.
     */
    public static function custom(string $name, callable $handler, bool $global = false): self
    {
        return new self(self::CUSTOM, $name, $handler, $global);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getHandler(): Closure
    {
        return $this->handler;
    }

    public function isGlobal(): bool
    {
        return $this->global;
    }

    /**
     * Makes the route reachable from inside scenes as well.
     */
    public function global(bool $global = true): self
    {
        $this->global = $global;

        return $this;
    }

    public function matches(InboundEventInterface $event): bool
    {
        return match ($this->type) {
            self::FALLBACK, self::CUSTOM => true,
            self::COMMAND => self::matchesCommand($this->pattern, $event->getText()),
            self::TEXT_PREFIX => str_starts_with($event->getText(), $this->pattern),
            self::TEXT_REGEX => @preg_match($this->pattern, $event->getText()) === 1,
            self::ACTION => ($event->getActionId() ?? '') === $this->pattern,
            self::ACTION_PREFIX => str_starts_with($event->getActionId() ?? '', $this->pattern),
            self::ACTION_REGEX => @preg_match($this->pattern, $event->getActionId() ?? '') === 1,
            default => false,
        };
    }

    /**
     * Matches "/name", "/name args", and the group form "/name@bot_username args".
     */
    public static function matchesCommand(string $command, string $text): bool
    {
        $command = ltrim($command, '/');

        if ($command === '') {
            return false;
        }

        return preg_match('~^/' . preg_quote($command, '~') . '(?:@[A-Za-z0-9_]+)?(?:\s|$)~u', $text) === 1;
    }

    /**
     * @param MiddlewareInterface|class-string<MiddlewareInterface>|list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     */
    public function middleware(MiddlewareInterface|string|array $middleware): self
    {
        if (\is_array($middleware)) {
            $this->middlewares = [...$this->middlewares, ...$middleware];
        } else {
            $this->middlewares[] = $middleware;
        }

        return $this;
    }

    /**
     * @return list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
}
