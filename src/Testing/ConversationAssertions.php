<?php

declare(strict_types=1);

namespace ChatFlow\Testing;

use ChatFlow\Core\Application;
use ChatFlow\Core\Result;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Scene\Conversation;
use ChatFlow\Scene\RootScene;
use ChatFlow\SideEffect\SideEffect;
use ChatFlow\Timer\Timer;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/**
 * Asserts on the state of a conversation in an Application: current scene, session data,
 * pending scene transitions, side effects, timers and execution results. Shared by platform
 * testers (such as ApplicationTester and TelegramBotTester).
 *
 * Requires phpunit/phpunit.
 */
final class ConversationAssertions
{
    private ?Result $lastResult = null;

    public function __construct(
        private readonly Application $application,
        private readonly string $conversationId,
    ) {}

    public function recordResult(?Result $result): void
    {
        $this->lastResult = $result;
    }

    public function getLastResult(): ?Result
    {
        return $this->lastResult;
    }

    public function conversation(): ConversationRef
    {
        return new ConversationRef($this->conversationId);
    }

    public function resume(): Conversation
    {
        return $this->application->getConversations()->resume($this->conversationId);
    }

    public function assertScene(string $scene): self
    {
        $expected = $scene === RootScene::ID ? $scene : $this->application->getScenes()->resolveId($scene);
        Assert::assertSame($expected, $this->resume()->getCurrentScene());

        return $this;
    }

    public function assertNotInScene(): self
    {
        Assert::assertFalse($this->resume()->inScene(), 'Expected the conversation in the root scene.');

        return $this;
    }

    public function assertScenePending(string $scene): self
    {
        $pending = $this->application->getConversations()->getPending($this->conversationId);

        Assert::assertNotNull($pending, 'No scene transition is pending.');
        Assert::assertSame('enter', $pending['action']);
        Assert::assertSame($this->application->getScenes()->resolveId($scene), $pending['scene']);

        return $this;
    }

    public function assertNoScenePending(): self
    {
        Assert::assertNull($this->application->getConversations()->getPending($this->conversationId));

        return $this;
    }

    public function assertSessionHas(string $key, mixed $expected = null): self
    {
        $session = $this->resume()->getContext();
        Assert::assertTrue($session->has($key), \sprintf('Session key "%s" is missing.', $key));

        if (\func_num_args() === 2) {
            Assert::assertSame($expected, $session->get($key));
        }

        return $this;
    }

    public function assertSessionMissing(string $key): self
    {
        Assert::assertFalse($this->resume()->getContext()->has($key), \sprintf('Session key "%s" should be missing.', $key));

        return $this;
    }

    public function assertResult(string $status, ?string $message = null): self
    {
        Assert::assertNotNull($this->lastResult, 'No event was dispatched yet.');
        Assert::assertSame($status, $this->lastResult->getStatus());

        if ($message !== null) {
            Assert::assertSame($message, $this->lastResult->getMessage());
        }

        return $this;
    }

    public function assertSideEffectPending(string $handler): self
    {
        Assert::assertContains($handler, $this->pendingHandlers(), \sprintf('No pending side effect for "%s".', $handler));

        return $this;
    }

    public function assertNoSideEffectsPending(): self
    {
        Assert::assertSame([], $this->pendingHandlers(), 'Side effects are still pending.');

        return $this;
    }

    public function assertSideEffectFailed(string $handler): self
    {
        $failed = array_map(static fn(SideEffect $effect): string => $effect->handler, $this->resume()->getContext()->getFailedSideEffects());
        Assert::assertContains($handler, $failed, \sprintf('No failed side effect for "%s".', $handler));

        return $this;
    }

    public function assertTimerScheduled(string $reason, ?DateTimeImmutable $at = null): self
    {
        $timer = $this->timer($reason);
        Assert::assertNotNull($timer, \sprintf('No timer with reason "%s" is scheduled.', $reason));

        if ($at !== null) {
            Assert::assertSame($at->getTimestamp(), $timer->at->getTimestamp());
        }

        return $this;
    }

    public function assertNoTimer(string $reason): self
    {
        Assert::assertNull($this->timer($reason), \sprintf('A timer with reason "%s" is scheduled.', $reason));

        return $this;
    }

    /**
     * @return list<string>
     */
    private function pendingHandlers(): array
    {
        return array_map(static fn(SideEffect $effect): string => $effect->handler, $this->resume()->getContext()->getSideEffects());
    }

    private function timer(string $reason): ?Timer
    {
        $timers = $this->application->getTimers();

        if ($timers === null) {
            return null;
        }

        foreach ($timers->forConversation($this->conversationId) as $timer) {
            if ($timer->reason === $reason) {
                return $timer;
            }
        }

        return null;
    }
}
