<?php

declare(strict_types=1);

namespace ChatFlow\Core;

use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\ValidationException;
use Closure;
use ReflectionFunction;

/**
 * Turns a handler reference into a method name that can be stored in the conversation.
 *
 * Scene handlers are persisted between updates, so only named methods are accepted: closures
 * cannot be serialized.
 */
final class ClosureResolver
{
    /**
     * @param string|array<int, mixed>|Closure $handler
     *
     * @throws LogicException When an anonymous function is given.
     * @throws ValidationException When the array form is malformed.
     */
    public static function resolveName(string|array|Closure $handler): string
    {
        if (\is_string($handler)) {
            return $handler;
        }

        if (\is_array($handler)) {
            if (\count($handler) === 2 && isset($handler[1]) && \is_string($handler[1])) {
                return $handler[1];
            }

            throw new ValidationException('Array handlers must have the form [object or class, method name].');
        }

        $name = (new ReflectionFunction($handler))->getName();

        if (str_contains($name, '{closure')) {
            throw new LogicException('Anonymous functions are not supported as scene handlers; use a named method instead.');
        }

        return $name;
    }
}
