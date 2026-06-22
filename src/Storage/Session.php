<?php

declare(strict_types=1);

namespace ChatFlow\Storage;

use ChatFlow\Support\SerializableValueValidator;

final class Session
{
    private ?string $currentScene = null;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<int, array{class: string, title: ?string}> */
    private array $history = [];

    private ?string $pendingScene = null;

    /** @var array<string, mixed> */
    private array $pendingSceneData = [];

    private bool $pendingExit = false;

    /** @var array{validators: array<int, array{rule: string, error: ?string}>, fallbacks: array<int, array{type: string, pattern: string|array<string>, handler: string}>, handler: ?string}|null */
    private ?array $interaction = null;

    public function __construct(
        private readonly string $conversationId,
        private int $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $meta = $data['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        $conversationIdValue = $meta['conversation_id'] ?? ($meta['chat_id'] ?? '');
        $conversationId = is_scalar($conversationIdValue) ? (string) $conversationIdValue : '';
        $updatedAtValue = $meta['updated_at'] ?? time();
        $updatedAt = is_int($updatedAtValue) ? $updatedAtValue : time();

        $session = new self($conversationId, $updatedAt);

        $sessionData = $data['data'] ?? [];
        if (is_array($sessionData)) {
            /* @var array<string, mixed> $sessionData */
            $session->data = SerializableValueValidator::normalizeMap($sessionData, 'session data');
        }

        $currentSceneValue = $meta['current_scene'] ?? ($data['current_scene'] ?? null);
        if (is_string($currentSceneValue) || $currentSceneValue === null) {
            $session->currentScene = $currentSceneValue;
        }

        $historyValue = $meta['history'] ?? [];
        if (is_array($historyValue)) {
            /** @var array<int, array{class: string, title: ?string}> $history */
            $history = array_values(array_filter(
                $historyValue,
                static fn (mixed $item): bool => is_array($item) && isset($item['class']) && is_string($item['class'])
            ));
            $session->history = $history;
        }

        $interactionValue = $meta['interaction'] ?? null;
        if (is_array($interactionValue)) {
            /** @var array{validators: array<int, array{rule: string, error: ?string}>, fallbacks: array<int, array{type: string, pattern: string|array<string>, handler: string}>, handler: ?string} $interaction */
            $interaction = $interactionValue;
            $session->interaction = $interaction;
        }

        $pendingSceneValue = $meta['pending_scene'] ?? null;
        if (is_string($pendingSceneValue) || $pendingSceneValue === null) {
            $session->pendingScene = $pendingSceneValue;
        }

        $pendingSceneDataValue = $meta['pending_scene_data'] ?? [];
        if (is_array($pendingSceneDataValue)) {
            /** @var array<string, mixed> $pendingSceneData */
            $pendingSceneData = $pendingSceneDataValue;
            $session->pendingSceneData = SerializableValueValidator::normalizeMap($pendingSceneData, 'pending scene data');
        }

        $pendingExitValue = $meta['pending_exit'] ?? false;
        if (is_bool($pendingExitValue)) {
            $session->pendingExit = $pendingExitValue;
        }

        return $session;
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function getCurrentScene(): ?string
    {
        return $this->currentScene;
    }

    public function setCurrentScene(?string $sceneClass): void
    {
        $this->currentScene = $sceneClass;
        $this->touch();
    }

    public function hasScene(): bool
    {
        return $this->currentScene !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = SerializableValueValidator::normalize($value, "session data.{$key}");
        $this->touch();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function clearData(): void
    {
        $this->data = [];
        $this->touch();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function requestScene(string $sceneClass, array $data = []): void
    {
        $this->pendingScene = $sceneClass;
        $this->pendingSceneData = SerializableValueValidator::normalizeMap($data, 'pending scene data');
        $this->touch();
    }

    public function getPendingScene(): ?string
    {
        return $this->pendingScene;
    }

    public function clearPendingScene(): void
    {
        $this->pendingScene = null;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPendingSceneData(): array
    {
        return $this->pendingSceneData;
    }

    public function clearPendingSceneData(): void
    {
        $this->pendingSceneData = [];
        $this->touch();
    }

    public function requestExit(): void
    {
        $this->pendingExit = true;
        $this->touch();
    }

    public function isExitPending(): bool
    {
        return $this->pendingExit;
    }

    public function clearPendingExit(): void
    {
        $this->pendingExit = false;
        $this->touch();
    }

    /**
     * @param array{validators: array<int, array{rule: string, error: ?string}>, fallbacks: array<int, array{type: string, pattern: string|array<string>, handler: string}>, handler: ?string} $config
     */
    public function setInteraction(array $config): void
    {
        /** @var array{validators: array<int, array{rule: string, error: ?string}>, fallbacks: array<int, array{type: string, pattern: string|array<string>, handler: string}>, handler: ?string} $normalized */
        $normalized = SerializableValueValidator::normalize($config, 'interaction');
        $this->interaction = $normalized;
        $this->touch();
    }

    /**
     * @return array{validators: array<int, array{rule: string, error: ?string}>, fallbacks: array<int, array{type: string, pattern: string|array<string>, handler: string}>, handler: ?string}|null
     */
    public function getInteraction(): ?array
    {
        return $this->interaction;
    }

    public function hasInteraction(): bool
    {
        return $this->interaction !== null;
    }

    public function clearInteraction(): void
    {
        $this->interaction = null;
        $this->touch();
    }

    public function pushHistory(string $sceneClass, ?string $title = null): void
    {
        if ($this->history !== []) {
            /** @var array{class: string, title: ?string}|false $lastItem */
            $lastItem = end($this->history);
            if ($lastItem !== false && $lastItem['class'] === $sceneClass) {
                return;
            }
        }

        $this->history[] = ['class' => $sceneClass, 'title' => $title];
        $this->touch();
    }

    /**
     * @return array{class: string, title: ?string}|null
     */
    public function popHistory(): ?array
    {
        if ($this->history === []) {
            return null;
        }

        /** @var array{class: string, title: ?string} $scene */
        $scene = array_pop($this->history);
        $this->touch();

        return $scene;
    }

    /**
     * @return array<int, array{class: string, title: ?string}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function updateHistoryTitle(string $title): void
    {
        if ($this->history === []) {
            return;
        }

        $lastIndex = count($this->history) - 1;
        $this->history[$lastIndex]['title'] = $title;
        $this->touch();
    }

    public function clearHistory(): void
    {
        $this->history = [];
        $this->touch();
    }

    /**
     * @return array{class: string, title: ?string}|null
     */
    public function peekHistory(): ?array
    {
        if ($this->history === []) {
            return null;
        }

        /** @var array{class: string, title: ?string}|false $item */
        $item = end($this->history);

        return $item === false ? null : $item;
    }

    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(int $timestamp): void
    {
        $this->updatedAt = $timestamp;
    }

    /**
     * @return array{meta: array<string, mixed>, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        $data = SerializableValueValidator::normalizeMap($this->data, 'session data');
        $pendingSceneData = SerializableValueValidator::normalizeMap($this->pendingSceneData, 'pending scene data');

        return [
            'meta' => [
                'conversation_id' => $this->conversationId,
                'current_scene' => $this->currentScene,
                'updated_at' => $this->updatedAt,
                'history' => $this->history,
                'interaction' => $this->interaction,
                'pending_scene' => $this->pendingScene,
                'pending_scene_data' => $pendingSceneData,
                'pending_exit' => $this->pendingExit,
            ],
            'data' => $data,
        ];
    }

    private function touch(): void
    {
        $this->updatedAt = time();
    }
}
