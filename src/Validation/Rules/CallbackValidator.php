<?php

declare(strict_types=1);

namespace ChatFlow\Validation\Rules;

use ChatFlow\Exception\ValidationException;
use ChatFlow\Validation\ValidatorInterface;

class CallbackValidator implements ValidatorInterface
{
    public function __construct(private mixed $callback = null)
    {
    }

    public function setCallback(mixed $callback): void
    {
        $this->callback = $callback;
    }

    /**
     * Validate value using callback function.
     *
     * @param mixed                $value      Value to validate
     * @param array<string, mixed> $parameters Validation parameters
     *
     * @return bool True if valid
     *
     * @throws ValidationException If callback is not provided or not callable
     */
    public function validate(mixed $value, array $parameters = []): bool
    {
        if ($this->callback === null && $parameters === []) {
            throw new ValidationException('CallbackValidator requires a callback function');
        }

        $callback = $this->callback;
        if ($callback === null) {
            $values = array_values($parameters);
            $callback = $values[0] ?? null;
        }

        if ($callback === null) {
            throw new ValidationException('CallbackValidator requires a callback function');
        }

        if (is_callable($callback)) {
            return (bool) $callback($value, $parameters);
        }

        throw new ValidationException('CallbackValidator requires a callable function');
    }
}
