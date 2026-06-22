<?php

declare(strict_types=1);

namespace ChatFlow\Core;

use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\ValidationException;
use Closure;
use ReflectionFunction;

class ClosureResolver
{
    /**
     * Resolves the handler name to a string.
     *
     * Used for FSM state persistence. Anonymous functions (Closures) cannot be
     * reliably serialized/unserialized in session storage, so we enforce
     * using named methods for Scene handlers.
     *
     * @param string|array<int, mixed>|Closure $handler
     *
     * @throws LogicException      If an anonymous closure is passed
     * @throws ValidationException
     */
    public static function resolveName(string|array|Closure $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler)) {
            if (count($handler) === 2 && is_string($handler[1])) {
                return $handler[1];
            }
            throw new ValidationException('Invalid array handler format.');
        }

        // At this point, $handler must be Closure due to the union type
        $reflection = new ReflectionFunction($handler);
        $name = $reflection->getName();

        if (str_contains($name, '{closure}')) {
            throw new LogicException(
                'Anonymous functions (closures) are not supported as handlers. Use a named method instead.'
            );
        }

        return $name;
    }
}
