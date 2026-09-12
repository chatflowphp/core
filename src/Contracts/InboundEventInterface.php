<?php

declare(strict_types=1);

namespace ChatFlow\Contracts;

use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\MessageRef;
use ChatFlow\Event\UserRef;
use DateTimeImmutable;

interface InboundEventInterface
{
    public function getConversation(): ConversationRef;

    public function getConversationId(): string;

    public function getUser(): ?UserRef;

    public function getUserId(): string|int|null;

    public function getText(): string;

    public function isAction(): bool;

    public function getActionId(): ?string;

    public function getActionPayload(): mixed;

    /**
     * @return list<InboundAttachment>
     */
    public function getAttachments(): array;

    public function getMessageRef(): ?MessageRef;

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * When the event happened on the platform, not when it was processed. Adapters take it from
     * the platform payload; system events carry the time they were created.
     */
    public function getOccurredAt(): DateTimeImmutable;
}
