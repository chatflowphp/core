<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use Automata\Context\ArrayContext;
use ChatFlow\Core\Context;
use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\SceneException;
use ChatFlow\SideEffect\SideEffect;

/**
 * Conversation state shared by every scene: the automata context behind the conversation's
 * state machine. Everything stored here is part of the persisted snapshot and is rolled back
 * together with the current scene when a tick fails.
 *
 * Keys starting with "_" are reserved for the runtime. Scene history, the pending interaction,
 * scheduled side effects and adapter extensions live there; they are hidden from all() and keys() and cannot be written
 * through set().
 *
 * @phpstan-import-type InteractionConfig from Interaction
 * @phpstan-type HistoryEntry array{scene: string, title: string|null}
 * @phpstan-type PendingTransition array{action: 'enter'|'leave', scene: string|null, data: array<string, mixed>, title: string|null, handleTrigger: bool}
 */
class SceneContext extends ArrayContext
{
    public const RESERVED_PREFIX = '_';

    private const HISTORY_KEY = '_history';
    private const INTERACTION_KEY = '_interaction';
    private const PENDING_KEY = '_pending';
    private const EFFECTS_KEY = '_effects';
    private const FAILED_EFFECTS_KEY = '_effects_failed';
    private const EXTENSION_PREFIX = '_ext.';

    private ?Context $request = null;

    private ?string $returningTo = null;

    /**
     * @throws LogicException When the key is reserved for the runtime.
     */
    public function set(string $key, mixed $value): void
    {
        $this->assertWritable($key);
        parent::set($key, $value);
    }

    /**
     * @throws LogicException When the key is reserved for the runtime.
     */
    public function remove(string $key): void
    {
        $this->assertWritable($key);
        parent::remove($key);
    }

    /**
     * User data only, without reserved runtime keys.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $data = [];

        foreach ($this->getState() as $key => $value) {
            if (!self::isReserved($key)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Removes every user key and keeps runtime data (history, interaction, extensions).
     */
    public function clear(): void
    {
        foreach ($this->keys() as $key) {
            parent::remove($key);
        }
    }

    // -- scene history -------------------------------------------------------------------------

    public function pushHistory(string $sceneId, ?string $title = null): void
    {
        $history = $this->getHistory();
        $last = $history === [] ? null : $history[\count($history) - 1];

        if ($last !== null && $last['scene'] === $sceneId) {
            return;
        }

        $history[] = ['scene' => $sceneId, 'title' => $title];
        parent::set(self::HISTORY_KEY, $history);
    }

    /**
     * @return HistoryEntry|null
     */
    public function popHistory(): ?array
    {
        $history = $this->getHistory();

        if ($history === []) {
            return null;
        }

        $entry = array_pop($history);
        parent::set(self::HISTORY_KEY, $history);

        return $entry;
    }

    /**
     * @return HistoryEntry|null
     */
    public function peekHistory(): ?array
    {
        $history = $this->getHistory();

        return $history === [] ? null : $history[\count($history) - 1];
    }

    /**
     * @return list<HistoryEntry>
     */
    public function getHistory(): array
    {
        $raw = $this->get(self::HISTORY_KEY);

        if (!\is_array($raw)) {
            return [];
        }

        $history = [];

        foreach ($raw as $entry) {
            if (!\is_array($entry) || !isset($entry['scene']) || !\is_string($entry['scene'])) {
                continue;
            }

            $title = $entry['title'] ?? null;
            $history[] = ['scene' => $entry['scene'], 'title' => \is_string($title) ? $title : null];
        }

        return $history;
    }

    public function updateHistoryTitle(string $title): void
    {
        $history = $this->getHistory();

        if ($history === []) {
            return;
        }

        $history[\count($history) - 1]['title'] = $title;
        parent::set(self::HISTORY_KEY, $history);
    }

    public function clearHistory(): void
    {
        parent::remove(self::HISTORY_KEY);
    }

    // -- pending interaction -------------------------------------------------------------------

    /**
     * @param InteractionConfig $config
     */
    public function setInteraction(array $config): void
    {
        parent::set(self::INTERACTION_KEY, $config);
    }

    /**
     * @return InteractionConfig|null
     */
    public function getInteraction(): ?array
    {
        $raw = $this->get(self::INTERACTION_KEY);

        return \is_array($raw) ? Interaction::configFromArray($raw) : null;
    }

    public function hasInteraction(): bool
    {
        return $this->getInteraction() !== null;
    }

    public function clearInteraction(): void
    {
        parent::remove(self::INTERACTION_KEY);
    }

    // -- pending transition --------------------------------------------------------------------

    /**
     * Records a transition requested outside of a request (scheduler, admin action, another
     * chat). The runtime applies it when the conversation receives its next event.
     *
     * @param PendingTransition $transition
     */
    public function setPendingTransition(array $transition): void
    {
        parent::set(self::PENDING_KEY, $transition);
    }

    /**
     * @return PendingTransition|null
     */
    public function getPendingTransition(): ?array
    {
        $raw = $this->get(self::PENDING_KEY);

        if (!\is_array($raw)) {
            return null;
        }

        $action = $raw['action'] ?? null;
        $scene = $raw['scene'] ?? null;
        $title = $raw['title'] ?? null;
        $rawData = $raw['data'] ?? [];

        if (($action !== 'enter' && $action !== 'leave') || ($scene !== null && !\is_string($scene)) || !\is_array($rawData)) {
            return null;
        }

        $data = [];

        foreach ($rawData as $key => $value) {
            if (\is_string($key)) {
                $data[$key] = $value;
            }
        }

        return [
            'action' => $action,
            'scene' => $scene,
            'data' => $data,
            'title' => \is_string($title) ? $title : null,
            'handleTrigger' => ($raw['handleTrigger'] ?? false) === true,
        ];
    }

    public function hasPendingTransition(): bool
    {
        return $this->getPendingTransition() !== null;
    }

    public function clearPendingTransition(): void
    {
        parent::remove(self::PENDING_KEY);
    }

    // -- side effects --------------------------------------------------------------------------

    /**
     * Effects scheduled with Context::schedule() and not executed yet, in scheduling order.
     *
     * @return list<SideEffect>
     */
    public function getSideEffects(): array
    {
        return $this->readEffects(self::EFFECTS_KEY);
    }

    /**
     * @return bool False when an effect with the same id is already pending; nothing is changed then.
     */
    public function addSideEffect(SideEffect $effect): bool
    {
        $effects = $this->getSideEffects();

        foreach ($effects as $pending) {
            if ($pending->id === $effect->id) {
                return false;
            }
        }

        $effects[] = $effect;
        $this->writeEffects(self::EFFECTS_KEY, $effects);

        return true;
    }

    public function replaceSideEffect(SideEffect $effect): void
    {
        $effects = [];

        foreach ($this->getSideEffects() as $pending) {
            $effects[] = $pending->id === $effect->id ? $effect : $pending;
        }

        $this->writeEffects(self::EFFECTS_KEY, $effects);
    }

    public function removeSideEffect(string $id): void
    {
        $effects = [];

        foreach ($this->getSideEffects() as $pending) {
            if ($pending->id !== $id) {
                $effects[] = $pending;
            }
        }

        $this->writeEffects(self::EFFECTS_KEY, $effects);
    }

    /**
     * Effects the runtime gave up on: unknown handler, or too many failed attempts. Kept for the
     * application to inspect and clear.
     *
     * @return list<SideEffect>
     */
    public function getFailedSideEffects(): array
    {
        return $this->readEffects(self::FAILED_EFFECTS_KEY);
    }

    public function addFailedSideEffect(SideEffect $effect): void
    {
        $failed = $this->getFailedSideEffects();
        $failed[] = $effect;
        $this->writeEffects(self::FAILED_EFFECTS_KEY, $failed);
    }

    public function clearFailedSideEffects(): void
    {
        parent::remove(self::FAILED_EFFECTS_KEY);
    }

    /**
     * @return list<SideEffect>
     */
    private function readEffects(string $key): array
    {
        $raw = $this->get($key);
        $effects = [];

        foreach (\is_array($raw) ? $raw : [] as $item) {
            $effect = \is_array($item) ? SideEffect::fromArray($item) : null;

            if ($effect !== null) {
                $effects[] = $effect;
            }
        }

        return $effects;
    }

    /**
     * @param list<SideEffect> $effects
     */
    private function writeEffects(string $key, array $effects): void
    {
        if ($effects === []) {
            parent::remove($key);

            return;
        }

        parent::set($key, array_map(static fn(SideEffect $effect): array => $effect->toArray(), $effects));
    }

    // -- adapter extensions --------------------------------------------------------------------

    /**
     * Runtime data owned by an adapter or extension, kept apart from user keys.
     *
     * @return array<string, mixed>
     */
    public function getExtension(string $name): array
    {
        $raw = $this->get(self::EXTENSION_PREFIX . $name);

        if (!\is_array($raw)) {
            return [];
        }

        $data = [];

        foreach ($raw as $key => $value) {
            if (\is_string($key)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setExtension(string $name, array $data): void
    {
        if ($data === []) {
            parent::remove(self::EXTENSION_PREFIX . $name);

            return;
        }

        parent::set(self::EXTENSION_PREFIX . $name, $data);
    }

    // -- current request -----------------------------------------------------------------------

    /**
     * The inbound request being processed. Bound by the runtime for the duration of one tick and
     * never persisted.
     *
     * @internal
     */
    public function bindRequest(Context $request): void
    {
        $this->request = $request;
    }

    /**
     * @internal
     */
    public function unbindRequest(): void
    {
        $this->request = null;
    }

    public function hasRequest(): bool
    {
        return $this->request !== null;
    }

    /**
     * @throws SceneException When called outside of a tick.
     */
    public function request(): Context
    {
        return $this->request ?? throw new SceneException(
            'No inbound request is bound to the conversation. Scene hooks run only while Application::handle() processes an event.',
        );
    }

    /**
     * Marks the scene a back() navigation is heading to, so the transition policy can allow it.
     *
     * @internal
     */
    public function markReturningTo(?string $sceneId): void
    {
        $this->returningTo = $sceneId;
    }

    public function isReturningTo(string $sceneId): bool
    {
        return $this->returningTo === $sceneId;
    }

    public static function isReserved(string $key): bool
    {
        return str_starts_with($key, self::RESERVED_PREFIX);
    }

    private function assertWritable(string $key): void
    {
        if (self::isReserved($key)) {
            throw new LogicException(\sprintf(
                'Key "%s" is reserved for the ChatFlow runtime: keys starting with "%s" cannot be written directly.',
                $key,
                self::RESERVED_PREFIX,
            ));
        }
    }
}
