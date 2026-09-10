<?php

declare(strict_types=1);

namespace ChatFlow\Middleware;

use ChatFlow\Core\Context;
use Psr\Log\LoggerInterface;
use Throwable;

class LoggerMiddleware implements MiddlewareInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function process(Context $ctx, callable $next): mixed
    {
        $startTime = microtime(true);

        $this->logger->info('Incoming request', [
            'conversation_id' => $ctx->getConversationId(),
            'user_id' => $ctx->getUserId(),
            'text' => $ctx->getText(),
            'action_id' => $ctx->getActionId(),
        ]);

        try {
            $result = $next($ctx);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->info('Request processed', [
                'conversation_id' => $ctx->getConversationId(),
                'execution_time_ms' => $executionTime,
            ]);

            return $result;
        } catch (Throwable $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->error('Request failed', [
                'conversation_id' => $ctx->getConversationId(),
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTime,
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
