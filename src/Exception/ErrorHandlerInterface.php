<?php

declare(strict_types=1);

namespace ChatFlow\Exception;

use ChatFlow\Core\Context;
use Throwable;

interface ErrorHandlerInterface
{
    public function register(string $exceptionClass, callable $handler): void;

    public function handle(Throwable $e, ?Context $context): void;
}
