<?php

declare(strict_types=1);

namespace ChatFlow\Outbound;

final class DeliveryResult
{
    /**
     * @param array<string, mixed>|null $data
     */
    private function __construct(
        private readonly bool $success,
        private readonly ?string $message = null,
        private readonly ?array $data = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function success(?string $message = null, ?array $data = null): self
    {
        return new self(true, $message, $data);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function error(string $message, ?array $data = null): self
    {
        return new self(false, $message, $data);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isError(): bool
    {
        return !$this->success;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }
}
