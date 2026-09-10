<?php

declare(strict_types=1);

namespace ChatFlow\Validation\Rules;

use ChatFlow\Validation\ValidatorInterface;

class RequiredValidator implements ValidatorInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function validate(mixed $value, array $parameters = []): bool
    {
        if ($value === null) {
            return false;
        }

        if (!\is_string($value) && !is_numeric($value)) {
            return false;
        }

        /** @var string $valueStr */
        $valueStr = \is_string($value) ? $value : (string) $value;

        return trim($valueStr) !== '';
    }
}
