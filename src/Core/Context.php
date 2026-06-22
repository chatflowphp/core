<?php

declare(strict_types=1);

namespace ChatFlow\Core;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Contracts\PlatformAdapterInterface;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\MessageRef;
use ChatFlow\Event\UserRef;
use ChatFlow\Exception\FSMException;
use ChatFlow\Exception\UnsupportedCapabilityException;
use ChatFlow\FSM\StateManager;
use ChatFlow\Observability\NullRuntimeObserver;
use ChatFlow\Observability\RuntimeEvent;
use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Outbound\AckEffect;
use ChatFlow\Outbound\OutboundEffectInterface;
use ChatFlow\Outbound\RenderEffect;
use ChatFlow\Outbound\ReplyEffect;
use ChatFlow\Storage\Session;
use ChatFlow\View\View;

class Context
{
    /** @var array<string, mixed> */
    private array $items = [];

    /** @var list<OutboundEffectInterface> */
    private array $effects = [];

    public function __construct(
        private readonly InboundEventInterface $event,
        private readonly PlatformAdapterInterface $adapter,
        private readonly ContainerInterface $container,
        private ?StateManager $stateManager = null,
        private ?Session $session = null,
        private ?RuntimeObserverInterface $runtimeObserver = null,
    ) {
        $this->runtimeObserver ??= new NullRuntimeObserver();
    }

    public function getEvent(): InboundEventInterface
    {
        return $this->event;
    }

    public function getConversation(): ConversationRef
    {
        return $this->event->getConversation();
    }

    public function getConversationId(): string
    {
        return $this->event->getConversationId();
    }

    public function getUser(): ?UserRef
    {
        return $this->event->getUser();
    }

    public function getUserId(): string|int|null
    {
        return $this->event->getUserId();
    }

    public function getText(): string
    {
        return $this->event->getText();
    }

    public function isAction(): bool
    {
        return $this->event->isAction();
    }

    public function getActionId(): ?string
    {
        return $this->event->getActionId();
    }

    public function getActionPayload(): mixed
    {
        return $this->event->getActionPayload();
    }

    /**
     * @return list<InboundAttachment>
     */
    public function getAttachments(): array
    {
        return $this->event->getAttachments();
    }

    public function hasAttachment(?string $type = null): bool
    {
        foreach ($this->event->getAttachments() as $attachment) {
            $attachmentType = $attachment->getType();

            if ($type === null || $type === 'any' || $attachmentType === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return InboundAttachment|null
     */
    public function getFirstAttachment(?string $type = null): ?InboundAttachment
    {
        foreach ($this->event->getAttachments() as $attachment) {
            $attachmentType = $attachment->getType();

            if ($type === null || $type === 'any' || $attachmentType === $type) {
                return $attachment;
            }
        }

        return null;
    }

    public function getMessageRef(): ?MessageRef
    {
        return $this->event->getMessageRef();
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->event->getMetadata();
    }

    public function reply(View|string $view): ReplyEffect
    {
        $normalized = $this->normalizeView($view);
        $this->assertViewSupported($normalized, false);
        $effect = new ReplyEffect($normalized);
        $this->enqueueEffect($effect);

        return $effect;
    }

    public function render(View $view): RenderEffect
    {
        $this->assertViewSupported($view, true);
        $effect = new RenderEffect($view);
        $this->enqueueEffect($effect);

        return $effect;
    }

    public function ack(?string $text = null, bool $error = false): AckEffect
    {
        if (!$this->adapter->capabilities()->supportsAck()) {
            throw new UnsupportedCapabilityException('Current platform does not support acknowledgements.');
        }

        $effect = new AckEffect($text, $error);
        $this->enqueueEffect($effect);

        return $effect;
    }

    public function enqueueEffect(OutboundEffectInterface $effect): OutboundEffectInterface
    {
        $this->effects[] = $effect;
        $this->recordEffectQueued($effect);

        return $effect;
    }

    public function downloadAttachment(string $destinationDir): ?string
    {
        if (!$this->adapter->capabilities()->supportsAttachmentDownload()) {
            throw new UnsupportedCapabilityException('Current platform does not support attachment download.');
        }

        return $this->adapter->downloadAttachment($this, $destinationDir);
    }

    /**
     * @return list<OutboundEffectInterface>
     */
    public function getOutboundEffects(): array
    {
        return $this->effects;
    }

    public function clearOutboundEffects(): void
    {
        $this->effects = [];
    }

    public function setStateManager(?StateManager $stateManager): void
    {
        $this->stateManager = $stateManager;
    }

    public function getStateManager(): ?StateManager
    {
        return $this->stateManager;
    }

    public function setSession(?Session $session): void
    {
        $this->session = $session;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    /**
     * @throws FSMException
     */
    public function session(): Session
    {
        if ($this->session === null) {
            throw new FSMException(
                'Sessions are not active. Configure a storage-backed StateManager to use scene features.'
            );
        }

        return $this->session;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws FSMException
     * @throws \ChatFlow\Exception\StorageException
     * @throws \ChatFlow\Exception\ContainerException
     * @throws \ChatFlow\Exception\SceneNotFoundException
     */
    public function enter(string $sceneClass, array $data = [], ?string $historyTitle = null): void
    {
        if ($this->stateManager === null) {
            throw new FSMException('State manager is not configured.');
        }

        $session = $this->session();
        $currentScene = $session->getCurrentScene();
        if ($currentScene !== null) {
            $session->pushHistory($currentScene, $historyTitle);
        }

        $session->requestScene($sceneClass, $data);
        $this->stateManager->saveSession($session);
        $this->stateManager->processScene($this, true);
        $this->record('scene.entered', ['scene' => $sceneClass]);
    }

    /**
     * @throws FSMException
     * @throws \ChatFlow\Exception\StorageException
     * @throws \ChatFlow\Exception\ContainerException
     * @throws \ChatFlow\Exception\SceneNotFoundException
     */
    public function back(): void
    {
        if ($this->stateManager === null) {
            throw new FSMException('State manager is not configured.');
        }

        $previousScene = $this->session()->popHistory();
        if ($previousScene === null) {
            return;
        }

        $this->session()->requestScene($previousScene['class']);
        $this->stateManager->saveSession($this->session());
        $this->stateManager->processScene($this, true);
    }

    /**
     * @throws FSMException
     * @throws \ChatFlow\Exception\StorageException
     */
    public function leave(): void
    {
        if ($this->stateManager === null) {
            throw new FSMException('State manager is not configured.');
        }

        $session = $this->session();
        $session->setCurrentScene(null);
        $session->clearPendingScene();
        $session->clearPendingSceneData();
        $session->clearPendingExit();
        $session->clearInteraction();
        $this->stateManager->saveSession($session);
    }

    /**
     * @throws FSMException
     * @throws \ChatFlow\Exception\StorageException
     */
    public function clearHistory(): void
    {
        $this->session()->clearHistory();

        if ($this->stateManager !== null) {
            $this->stateManager->saveSession($this->session());
        }
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function set(string $key, mixed $value): void
    {
        $this->items[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function remove(string $key): void
    {
        unset($this->items[$key]);
    }

    private function normalizeView(View|string $view): View
    {
        if ($view instanceof View) {
            return $view;
        }

        return View::text($view);
    }

    private function recordEffectQueued(OutboundEffectInterface $effect): void
    {
        $this->record('effect.queued', ['effect' => $effect->getType()]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record(string $name, array $data = []): void
    {
        $this->runtimeObserver?->record(new RuntimeEvent($name, $this->getConversationId(), $data));
    }

    private function assertViewSupported(View $view, bool $render): void
    {
        $capabilities = $this->adapter->capabilities();

        if ($render && !$capabilities->supportsScreenRender()) {
            throw new UnsupportedCapabilityException('Current platform does not support screen rendering.');
        }

        if ($view->getActions() !== [] && !$capabilities->supportsActions()) {
            throw new UnsupportedCapabilityException('Current platform does not support actions.');
        }

        if ($view->getChoices() !== [] && !$capabilities->supportsChoices()) {
            throw new UnsupportedCapabilityException('Current platform does not support choices.');
        }

        if ($view->getMedia() !== [] && !$capabilities->supportsMedia()) {
            throw new UnsupportedCapabilityException('Current platform does not support media.');
        }
    }
}
