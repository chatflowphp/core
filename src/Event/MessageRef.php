<?php

declare(strict_types=1);

namespace ChatFlow\Event;

use ChatFlow\Support\SerializableValueValidator;

/**
 * Platform delivery metadata of the inbound message, used by adapters to edit, answer or reply.
 */
final class MessageRef
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $platformData;

    /**
     * @param array<string, mixed> $platformData
     */
    public function __construct(
        private readonly ?string $id = null,
        private readonly ?string $threadId = null,
        private readonly ?string $replyToken = null,
        array $platformData = [],
    ) {
        $this->platformData = SerializableValueValidator::normalizeMap($platformData, 'message ref platform data');
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getThreadId(): ?string
    {
        return $this->threadId;
    }

    public function getReplyToken(): ?string
    {
        return $this->replyToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlatformData(): array
    {
        return $this->platformData;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->platformData[$key] ?? $default;
    }
}
