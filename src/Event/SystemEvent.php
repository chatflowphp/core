<?php

declare(strict_types=1);

namespace ChatFlow\Event;

use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Support\SerializableValueValidator;
use DateTimeImmutable;
use DateTimeZone;

/**
 * An event that did not come from the user: a scheduler, an admin action or another chat acting
 * on a conversation. It carries no text, no action and no message reference, so replies are sent
 * as new messages to the conversation.
 */
final class SystemEvent implements InboundEventInterface
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $metadata;

    private readonly DateTimeImmutable $occurredAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly ConversationRef $conversation,
        private readonly ?UserRef $user = null,
        private readonly string $reason = 'system',
        array $metadata = [],
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->metadata = SerializableValueValidator::normalizeMap(['system' => true, 'reason' => $reason] + $metadata, 'metadata');
    }

    public static function forConversation(string $conversationId, string $reason = 'system'): self
    {
        return new self(new ConversationRef($conversationId), reason: $reason);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getConversation(): ConversationRef
    {
        return $this->conversation;
    }

    public function getConversationId(): string
    {
        return $this->conversation->getId();
    }

    public function getUser(): ?UserRef
    {
        return $this->user;
    }

    public function getUserId(): string|int|null
    {
        return $this->user?->getId();
    }

    public function getText(): string
    {
        return '';
    }

    public function isAction(): bool
    {
        return false;
    }

    public function getActionId(): ?string
    {
        return null;
    }

    public function getActionPayload(): mixed
    {
        return null;
    }

    public function getAttachments(): array
    {
        return [];
    }

    public function getMessageRef(): ?MessageRef
    {
        return null;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
