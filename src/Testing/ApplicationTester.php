<?php

declare(strict_types=1);

namespace ChatFlow\Testing;

use Automata\Clock\FrozenClock;
use ChatFlow\Core\Application;
use ChatFlow\Core\Result;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\InboundEvent;
use ChatFlow\Event\SystemEvent;
use ChatFlow\Event\UserRef;
use ChatFlow\Exception\LogicException;
use ChatFlow\Scene\Conversation;
use ChatFlow\Scene\RootScene;
use ChatFlow\SideEffect\SideEffect;
use ChatFlow\Timer\Timer;
use ChatFlow\View\View;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/**
 * Drives an Application with synthetic events and asserts on what it replied, where the
 * conversation is, what it scheduled and what it stored. Works with any application built on the
 * FakePlatformAdapter; adapter packages wrap it with their own event builders.
 *
 * Requires phpunit/phpunit.
 */
final class ApplicationTester
{
    private ?Result $lastResult = null;

    private ?DateTimeImmutable $occurredAt = null;

    public function __construct(
        private readonly Application $application,
        private readonly FakePlatformAdapter $adapter,
        private string $conversationId = 'conv-1',
        private string|int|null $userId = 1,
        private readonly ?FrozenClock $clock = null,
    ) {}

    // -- driving -------------------------------------------------------------------------------

    /**
     * Switches the conversation (and user) the following events come from.
     */
    public function as(string $conversationId, string|int|null $userId = null): self
    {
        $this->conversationId = $conversationId;
        $this->userId = $userId ?? $conversationId;

        return $this;
    }

    /**
     * Stamps the following events with this platform time.
     */
    public function at(DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function send(string $text): self
    {
        return $this->dispatch(new InboundEvent($this->conversation(), $this->user(), $text, occurredAt: $this->occurredAt));
    }

    public function press(string $actionId, mixed $payload = null): self
    {
        return $this->dispatch(new InboundEvent($this->conversation(), $this->user(), actionId: $actionId, actionPayload: $payload, occurredAt: $this->occurredAt));
    }

    public function attach(InboundAttachment $attachment, string $caption = ''): self
    {
        return $this->dispatch(new InboundEvent($this->conversation(), $this->user(), $caption, attachments: [$attachment], occurredAt: $this->occurredAt));
    }

    /**
     * Handles a system event with the given reason, the way a scheduler would.
     */
    public function system(string $reason = 'system'): self
    {
        return $this->dispatch(new SystemEvent($this->conversation(), $this->user(), $reason, occurredAt: $this->occurredAt));
    }

    public function dispatch(InboundEvent|SystemEvent $event): self
    {
        $this->lastResult = $this->application->handle($event);

        return $this;
    }

    /**
     * Moves the frozen clock forward (or to a point in time) and runs the timers that became due.
     *
     * @return int How many timers ran
     */
    public function travel(DateInterval|DateTimeImmutable|int $to): int
    {
        $clock = $this->clock ?? throw new LogicException('travel() needs the FrozenClock the application was built with; pass it to the tester.');
        $now = match (true) {
            $to instanceof DateTimeImmutable => $to,
            $to instanceof DateInterval => $clock->now()->add($to),
            default => $clock->now()->add(new DateInterval('PT' . max(0, $to) . 'S')),
        };
        $clock->advance(\sprintf('%+d seconds', $now->getTimestamp() - $clock->now()->getTimestamp()));

        return $this->application->runDue($now);
    }

    public function drain(): int
    {
        return $this->application->drain($this->conversationId);
    }

    /**
     * Forgets recorded deliveries; the conversation itself is untouched.
     */
    public function clear(): self
    {
        $this->adapter->clear();
        $this->lastResult = null;

        return $this;
    }

    // -- reading -------------------------------------------------------------------------------

    public function conversation(): ConversationRef
    {
        return new ConversationRef($this->conversationId);
    }

    public function resume(): Conversation
    {
        return $this->application->getConversations()->resume($this->conversationId);
    }

    public function getLastResult(): ?Result
    {
        return $this->lastResult;
    }

    /**
     * @return list<View>
     */
    public function getReplies(): array
    {
        return $this->adapter->replies;
    }

    public function getLastReply(): ?View
    {
        $replies = $this->adapter->replies;

        return $replies === [] ? null : $replies[\count($replies) - 1];
    }

    /**
     * @return list<string>
     */
    public function getTexts(): array
    {
        return array_map(static fn(View $view): string => $view->getText(), [...$this->adapter->replies, ...$this->adapter->renders]);
    }

    // -- assertions ----------------------------------------------------------------------------

    public function assertSee(string $text): self
    {
        Assert::assertTrue(
            $this->anyTextContains($text),
            \sprintf('Expected a message containing "%s". Delivered: %s', $text, json_encode($this->getTexts(), JSON_UNESCAPED_UNICODE)),
        );

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertFalse($this->anyTextContains($text), \sprintf('Did not expect a message containing "%s".', $text));

        return $this;
    }

    public function assertReplied(string $text): self
    {
        $last = $this->getLastReply();
        Assert::assertNotNull($last, 'Expected a reply, none was delivered.');
        Assert::assertSame($text, $last->getText());

        return $this;
    }

    public function assertNoReply(): self
    {
        Assert::assertSame([], $this->adapter->replies, 'Expected no reply. Delivered: ' . json_encode($this->getTexts(), JSON_UNESCAPED_UNICODE));

        return $this;
    }

    public function assertRendered(string $text): self
    {
        $renders = $this->adapter->renders;
        Assert::assertNotSame([], $renders, 'Expected a render, none was delivered.');
        Assert::assertSame($text, $renders[\count($renders) - 1]->getText());

        return $this;
    }

    public function assertActionOffered(string $actionId): self
    {
        foreach ([...$this->adapter->replies, ...$this->adapter->renders] as $view) {
            foreach ($view->getActions() as $row) {
                foreach ($row as $action) {
                    if ($action->getId() === $actionId) {
                        return $this;
                    }
                }
            }
        }

        Assert::fail(\sprintf('No delivered message offers the action "%s".', $actionId));
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

    // -- internals -----------------------------------------------------------------------------

    private function user(): ?UserRef
    {
        return $this->userId === null ? null : new UserRef($this->userId);
    }

    private function anyTextContains(string $text): bool
    {
        foreach ($this->getTexts() as $delivered) {
            if (str_contains($delivered, $text)) {
                return true;
            }
        }

        return false;
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
