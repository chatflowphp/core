<?php

declare(strict_types=1);

namespace ChatFlow\Event;

use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Support\SerializableValueValidator;

/**
 * Platform-neutral inbound event. Payload and metadata are normalized once on construction.
 */
final class InboundEvent implements InboundEventInterface
{
    private readonly mixed $actionPayload;

    /**
     * @var array<string, mixed>
     */
    private readonly array $metadata;

    /**
     * @param list<InboundAttachment> $attachments
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly ConversationRef $conversation,
        private readonly ?UserRef $user = null,
        private readonly string $text = '',
        private readonly ?string $actionId = null,
        mixed $actionPayload = null,
        private readonly array $attachments = [],
        private readonly ?MessageRef $messageRef = null,
        array $metadata = [],
    ) {
        $this->actionPayload = SerializableValueValidator::normalize($actionPayload, 'action payload');
        $this->metadata = SerializableValueValidator::normalizeMap($metadata, 'metadata');
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
        return $this->text;
    }

    public function isAction(): bool
    {
        return $this->actionId !== null && $this->actionId !== '';
    }

    public function getActionId(): ?string
    {
        return $this->actionId;
    }

    public function getActionPayload(): mixed
    {
        return $this->actionPayload;
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getMessageRef(): ?MessageRef
    {
        return $this->messageRef;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
