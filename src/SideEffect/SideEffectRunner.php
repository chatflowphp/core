<?php

declare(strict_types=1);

namespace ChatFlow\SideEffect;

use ChatFlow\Exception\ConversationConflictException;
use ChatFlow\Exception\SideEffectException;
use ChatFlow\Observability\RuntimeEvent;
use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Scene\ConversationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes the pending side effects of a conversation, one at a time, each against a freshly
 * loaded snapshot: execute, record the outcome, hand the result back through the follow-up.
 *
 * An effect that throws stays pending with its attempt counted and is skipped for the rest of
 * this drain; after `maxAttempts` failures, or at once when its handler is unknown, it moves to
 * the failed list of the conversation, where the application can inspect it.
 */
final class SideEffectRunner
{
    /**
     * Upper bound on effects executed by one drain, so a follow-up that keeps scheduling new
     * effects cannot run forever inside one request.
     */
    private const MAX_PER_DRAIN = 50;

    private const MAX_RECORD_ATTEMPTS = 3;

    public function __construct(
        private readonly ConversationManager $conversations,
        private readonly SideEffectRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly RuntimeObserverInterface $observer,
        private readonly int $maxAttempts = 5,
    ) {}

    /**
     * @param callable(SideEffect, array<string, mixed>): void $followUp Receives the handler's result inside the conversation
     *
     * @return int How many effects were executed successfully
     */
    public function drain(string $conversationId, callable $followUp): int
    {
        $executed = 0;
        $skipped = [];

        for ($round = 0; $round < self::MAX_PER_DRAIN; $round++) {
            $effect = $this->nextPending($conversationId, $skipped);

            if ($effect === null) {
                break;
            }

            try {
                $handler = $this->registry->resolve($effect->handler);
            } catch (SideEffectException $exception) {
                $this->record('side_effect.abandoned', $conversationId, $effect, $exception->getMessage());
                $this->logger->error('Side effect abandoned: no handler', ['conversation_id' => $conversationId, 'effect' => $effect->id, 'handler' => $effect->handler]);
                $this->update($conversationId, $effect->withFailedAttempt($exception->getMessage()), failed: true);

                continue;
            }

            try {
                $result = $handler->handle($effect);
            } catch (Throwable $exception) {
                $updated = $effect->withFailedAttempt($exception->getMessage());
                $abandon = $updated->attempts >= $this->maxAttempts;
                $this->record($abandon ? 'side_effect.abandoned' : 'side_effect.failed', $conversationId, $updated, $exception->getMessage());
                $this->logger->log($abandon ? 'error' : 'warning', 'Side effect failed', [
                    'conversation_id' => $conversationId,
                    'effect' => $effect->id,
                    'handler' => $effect->handler,
                    'attempt' => $updated->attempts,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $this->update($conversationId, $updated, failed: $abandon);
                $skipped[$effect->id] = true;

                continue;
            }

            $this->update($conversationId, $effect, failed: false, done: true);
            $this->record('side_effect.executed', $conversationId, $effect);
            $executed++;

            if ($result !== null) {
                $followUp($effect, $result);
            }
        }

        return $executed;
    }

    /**
     * @param array<string, true> $skipped
     */
    private function nextPending(string $conversationId, array $skipped): ?SideEffect
    {
        foreach ($this->conversations->resume($conversationId)->getContext()->getSideEffects() as $effect) {
            if (!isset($skipped[$effect->id])) {
                return $effect;
            }
        }

        return null;
    }

    /**
     * Rewrites the effect's record against a fresh snapshot; a concurrent tick in between makes
     * the store refuse the write, and the change is retried on the newer snapshot.
     */
    private function update(string $conversationId, SideEffect $effect, bool $failed, bool $done = false): void
    {
        for ($attempt = 1; $attempt <= self::MAX_RECORD_ATTEMPTS; $attempt++) {
            $conversation = $this->conversations->resume($conversationId);
            $session = $conversation->getContext();

            if ($done || $failed) {
                $session->removeSideEffect($effect->id);
            } else {
                $session->replaceSideEffect($effect);
            }

            if ($failed) {
                $session->addFailedSideEffect($effect);
            }

            try {
                $conversation->persist();

                return;
            } catch (ConversationConflictException $exception) {
                $this->observer->record(new RuntimeEvent('side_effect.record_conflict', $conversationId, [
                    'effect' => $effect->id,
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]));
            }
        }

        $this->logger->error('Side effect outcome could not be recorded; it will run again', [
            'conversation_id' => $conversationId,
            'effect' => $effect->id,
        ]);
    }

    private function record(string $name, string $conversationId, SideEffect $effect, ?string $message = null): void
    {
        $this->observer->record(new RuntimeEvent($name, $conversationId, array_filter([
            'effect' => $effect->id,
            'handler' => $effect->handler,
            'attempts' => $effect->attempts,
            'message' => $message,
        ], static fn(mixed $value): bool => $value !== null)));
    }
}
