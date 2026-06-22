<?php

declare(strict_types=1);

namespace ChatFlow\Validation\Rules;

use ChatFlow\Exception\ValidationException;
use ChatFlow\Validation\ValidatorInterface;

class RegexValidator implements ValidatorInterface
{
    /**
     * Validate value using regex pattern.
     *
     * @param mixed                $value      Value to validate
     * @param array<string, mixed> $parameters Validation parameters (pattern)
     *
     * @return bool True if value matches pattern
     *
     * @throws ValidationException If pattern is not provided or invalid
     */
    public function validate(mixed $value, array $parameters = []): bool
    {
        if ($parameters === []) {
            throw new ValidationException('Regex rule requires a pattern parameter');
        }

        // Use array_values to get a list array with integer keys
        $values = array_values($parameters);

        if (!is_string($values[0])) {
            throw new ValidationException('Regex rule requires a valid pattern parameter');
        }

        /** @var string $pattern */
        $pattern = $values[0];

        if (!is_string($value) && !is_numeric($value)) {
            throw new ValidationException('Value must be string or numeric for regex validation');
        }

        /** @var string $valueStr */
        $valueStr = is_string($value) ? $value : (string) $value;

        return (bool) preg_match($pattern, $valueStr);
    }
}
