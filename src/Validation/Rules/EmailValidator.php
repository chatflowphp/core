<?php

declare(strict_types=1);

namespace ChatFlow\Validation\Rules;

use ChatFlow\Validation\ValidatorInterface;

class EmailValidator implements ValidatorInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function validate(mixed $value, array $parameters = []): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
