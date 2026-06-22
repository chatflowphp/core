<?php

declare(strict_types=1);

namespace ChatFlow\Event;

use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Support\SerializableValueValidator;

final class InboundEvent implements InboundEventInterface
{
    /**
     * @param list<InboundAttachment> $attachments
     * @param array<string, mixed>    $metadata
     */
    public function __construct(
        private readonly ConversationRef $conversation,
        private readonly ?UserRef $user = null,
        private readonly string $text = '',
        private readonly ?string $actionId = null,
        private readonly mixed $actionPayload = null,
        private readonly array $attachments = [],
        private readonly ?MessageRef $messageRef = null,
        private readonly array $metadata = [],
    ) {
        SerializableValueValidator::assertSerializable($this->actionPayload, 'action payload');
        SerializableValueValidator::assertSerializable($this->metadata, 'metadata');

        foreach ($this->attachments as $attachment) {
            $this->assertAttachment($attachment);
        }
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
        return SerializableValueValidator::normalize($this->actionPayload, 'action payload');
    }

    /**
     * @return list<InboundAttachment>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getMessageRef(): ?MessageRef
    {
        return $this->messageRef;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return SerializableValueValidator::normalizeMap($this->metadata, 'metadata');
    }

    private function assertAttachment(InboundAttachment $attachment): void
    {
    }
}
