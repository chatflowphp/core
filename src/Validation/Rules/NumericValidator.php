<?php

declare(strict_types=1);

namespace ChatFlow\Validation\Rules;

use ChatFlow\Validation\ValidatorInterface;

class NumericValidator implements ValidatorInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function validate(mixed $value, array $parameters = []): bool
    {
        return is_numeric($value);
    }
}
