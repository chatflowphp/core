<?php

declare(strict_types=1);

namespace ChatFlow\Observability;

use ChatFlow\Support\SerializableValueValidator;
use InvalidArgumentException;

final class RuntimeEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $conversationId = null,
        private readonly array $data = [],
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Runtime event name must be a non-empty string.');
        }

        SerializableValueValidator::assertSerializable($this->data, 'runtime event data');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return SerializableValueValidator::normalizeMap($this->data, 'runtime event data');
    }

    /**
     * @return array{name: string, conversation_id: ?string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'conversation_id' => $this->conversationId,
            'data' => $this->getData(),
        ];
    }
}
