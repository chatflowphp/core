<?php

declare(strict_types=1);

namespace ChatFlow\Event;

use ChatFlow\Support\SerializableValueValidator;

final class MessageRef
{
    /**
     * @param array<string, mixed> $platformData
     */
    public function __construct(
        private readonly ?string $id = null,
        private readonly ?string $threadId = null,
        private readonly ?string $replyToken = null,
        private readonly array $platformData = [],
    ) {
        SerializableValueValidator::assertSerializable($this->platformData, 'message ref platform data');
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
        return SerializableValueValidator::normalizeMap($this->platformData, 'message ref platform data');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->platformData[$key] ?? $default;
    }
}
