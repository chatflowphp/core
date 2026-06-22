<?php

declare(strict_types=1);

namespace ChatFlow\Validation;

use ChatFlow\Exception\ValidationException;

interface ValidatorInterface
{
    /**
     * @param array<string, mixed> $parameters
     *
     * @throws ValidationException
     */
    public function validate(mixed $value, array $parameters = []): bool;
}
