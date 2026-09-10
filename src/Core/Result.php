<?php

declare(strict_types=1);

namespace ChatFlow\Core;

class Result
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        private readonly string $status,
        private readonly ?string $message = null,
        private readonly ?array $data = null,
    ) {}

    /**
     * @param array<string, mixed>|null $data
     */
    public static function success(?string $message = null, ?array $data = null): self
    {
        return new self('success', $message, $data);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function error(string $message, ?array $data = null): self
    {
        return new self('error', $message, $data);
    }

    public static function noMatch(?string $message = null): self
    {
        return new self('no_match', $message);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isError(): bool
    {
        return $this->status === 'error';
    }

    public function isNoMatch(): bool
    {
        return $this->status === 'no_match';
    }

    public function getStatus(): string
    {
        return $this->status;
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
