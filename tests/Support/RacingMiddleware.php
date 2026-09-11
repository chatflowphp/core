<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Support;

use ChatFlow\Core\Application;
use ChatFlow\Core\Context;
use ChatFlow\Middleware\MiddlewareInterface;

/**
 * Lets another worker write the same conversation while this tick is running, which is what two
 * webhook requests for one chat do to each other.
 */
final class RacingMiddleware implements MiddlewareInterface
{
    public int $attempts = 0;

    public function __construct(
        private readonly Application $other,
        private readonly bool $once,
    ) {}

    public function process(Context $ctx, callable $next): mixed
    {
        $this->attempts++;

        if (!$this->once || $this->attempts === 1) {
            $this->other->handle(TestApp::event($ctx->getConversationId(), '/other'));
        }

        return $next($ctx);
    }
}
