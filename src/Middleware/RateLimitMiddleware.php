<?php

declare(strict_types=1);

namespace ChatFlow\Middleware;

use ChatFlow\Core\Context;
use ChatFlow\Core\Result;
use ChatFlow\Exception\StorageException;
use ChatFlow\Storage\StorageInterface;

/**
 * Soft per-user rate limit backed by any storage driver.
 *
 * The counter is read, incremented and written back without a lock, so concurrent requests may
 * exceed the limit by a few requests. Use an atomic counter (Redis INCR) for strict enforcement.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public const KEY_PREFIX = 'rate_limit:';

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly int $maxRequests = 20,
        private readonly int $windowSeconds = 60,
        private readonly ?string $rejectionMessage = null,
    ) {}

    /**
     * @throws StorageException
     */
    public function process(Context $ctx, callable $next): mixed
    {
        $userId = $ctx->getUserId();

        if ($userId === null) {
            return $next($ctx);
        }

        $key = self::KEY_PREFIX . $userId;
        $now = time();
        $record = $this->storage->get($key) ?? [];
        $resetAt = $record['reset_at'] ?? null;
        $count = $record['count'] ?? null;

        if (!\is_int($resetAt) || !\is_int($count) || $now > $resetAt) {
            $resetAt = $now + $this->windowSeconds;
            $count = 0;
        }

        $count++;
        $this->storage->save($key, ['count' => $count, 'reset_at' => $resetAt]);

        if ($count > $this->maxRequests) {
            if ($this->rejectionMessage !== null) {
                $ctx->reply($this->rejectionMessage);
            }

            return Result::error('rate_limited', ['reset_at' => $resetAt]);
        }

        return $next($ctx);
    }
}
