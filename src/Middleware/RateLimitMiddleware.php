<?php

declare(strict_types=1);

namespace ChatFlow\Middleware;

use ChatFlow\Core\Context;
use ChatFlow\Core\Result;
use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StorageInterface;

/**
 * Rate limiting middleware to protect against spam.
 *
 * NOTE: This implementation uses a "Check-then-Act" logic which is subject to race conditions
 * in high-concurrency environments. It serves as a "Soft Rate Limit".
 * For strict enforcement, use atomic counters (e.g. Redis INCR).
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private StorageInterface $storage,
        private int $maxRequests = 20,
        private int $windowSeconds = 60
    ) {
    }

    /**
     * Process the request through rate limiting.
     *
     * @param Context  $ctx  The context object
     * @param callable $next The next middleware in the pipeline
     *
     * @return mixed The result of the middleware chain
     *
     * @throws StorageException If storage driver fails
     */
    public function process(Context $ctx, callable $next): mixed
    {
        $userId = $ctx->getUserId();

        if ($userId === null) {
            return $next($ctx);
        }

        $userIdString = (string) $userId;

        $key = "rate_limit:{$userIdString}";
        $now = time();

        /** @var array{count: int, reset_at: int} $data */
        $data = $this->storage->get($key) ?? [
            'count' => 0,
            'reset_at' => $now + $this->windowSeconds,
        ];

        if ($now > $data['reset_at']) {
            $data = [
                'count' => 0,
                'reset_at' => $now + $this->windowSeconds,
            ];
        }

        /** @var int $currentCount */
        $currentCount = $data['count'];
        $data['count'] = $currentCount + 1;

        $this->storage->save($key, $data);

        if ((int) $data['count'] > $this->maxRequests) {
            return Result::error('Too many requests');
        }

        return $next($ctx);
    }
}
