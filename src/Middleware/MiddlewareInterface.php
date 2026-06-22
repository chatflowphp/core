<?php

declare(strict_types=1);

namespace ChatFlow\Middleware;

use ChatFlow\Core\Context;
use Throwable;

interface MiddlewareInterface
{
    /**
     * @throws Throwable
     */
    public function process(Context $ctx, callable $next): mixed;
}
