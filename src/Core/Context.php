<?php

declare(strict_types=1);

namespace ChatFlow\Core;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Contracts\PlatformAdapterInterface;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\MessageRef;
use ChatFlow\Event\SystemEvent;
use ChatFlow\Event\UserRef;
use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\SceneException;
use ChatFlow\Exception\SceneNotFoundException;
use ChatFlow\Exception\UnsupportedCapabilityException;
use ChatFlow\I18n\TranslatorInterface;
use ChatFlow\Observability\NullRuntimeObserver;
use ChatFlow\Observability\RuntimeEvent;
use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Outbound\AckEffect;
use ChatFlow\Outbound\OutboundEffectInterface;
use ChatFlow\Outbound\RenderEffect;
use ChatFlow\Outbound\ReplyEffect;
use ChatFlow\Routing\Route;
use ChatFlow\Scene\Conversation;
use ChatFlow\Scene\Interaction;
use ChatFlow\Scene\SceneContext;
use ChatFlow\View\View;

/**
 * Everything a handler needs for one inbound event: the event, the conversation, the outbound
 * effect queue and scene navigation.
 */
class Context
{
    /**
     * @var array<string, mixed>
     */
    private array $items = [];

    /**
     * @var list<OutboundEffectInterface>
     */
    private array $effects = [];

    private ?Conversation $conversation = null;

    private ?Route $route = null;

    public const LOCALE_KEY = 'locale';

    private readonly RuntimeObserverInterface $runtimeObserver;

    public function __construct(
        private readonly InboundEventInterface $event,
        private readonly PlatformAdapterInterface $adapter,
        private readonly ContainerInterface $container,
        ?RuntimeObserverInterface $runtimeObserver = null,
    ) {
        $this->runtimeObserver = $runtimeObserver ?? new NullRuntimeObserver();
    }

    // -- inbound event -------------------------------------------------------------------------

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

    /**
     * Whether the event was produced by the runtime (Application::run()) rather than by the user.
     */
    public function isSystem(): bool
    {
        return $this->event instanceof SystemEvent;
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
        return $this->getFirstAttachment($type) !== null;
    }

    public function getFirstAttachment(?string $type = null): ?InboundAttachment
    {
        foreach ($this->event->getAttachments() as $attachment) {
            if ($type === null || $type === 'any' || $attachment->getType() === $type) {
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

    // -- outbound effects ----------------------------------------------------------------------

    public function reply(View|string $view): ReplyEffect
    {
        $normalized = $view instanceof View ? $view : View::text($view);
        $this->assertViewSupported($normalized, false);
        $effect = new ReplyEffect($normalized);
        $this->enqueueEffect($effect);

        return $effect;
    }

    public function render(View|string $view): RenderEffect
    {
        $normalized = $view instanceof View ? $view : View::text($view);
        $this->assertViewSupported($normalized, true);
        $effect = new RenderEffect($normalized);
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
        $this->record('effect.queued', ['effect' => $effect->getType()]);

        return $effect;
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

    public function downloadAttachment(string $destinationDir): ?string
    {
        if (!$this->adapter->capabilities()->supportsAttachmentDownload()) {
            throw new UnsupportedCapabilityException('Current platform does not support attachment download.');
        }

        return $this->adapter->downloadAttachment($this, $destinationDir);
    }

    // -- conversation and scenes ---------------------------------------------------------------

    /**
     * @internal
     */
    public function attachConversation(Conversation $conversation): void
    {
        $this->conversation = $conversation;
    }

    public function hasConversation(): bool
    {
        return $this->conversation !== null;
    }

    /**
     * @throws SceneException When the context was created outside of Application::handle().
     */
    public function conversation(): Conversation
    {
        return $this->conversation ?? throw new SceneException(
            'No conversation is attached to this context. Sessions and scenes are available only inside Application::handle().',
        );
    }

    /**
     * Persistent conversation data. Typed getters, push() and increment() come from automata.
     */
    public function session(): SceneContext
    {
        return $this->conversation()->getContext();
    }

    public function getCurrentScene(): string
    {
        return $this->conversation()->getCurrentScene();
    }

    public function inScene(): bool
    {
        return $this->conversation()->inScene();
    }

    /**
     * Transitions into a scene inside the current tick. See Conversation::enter().
     *
     * @param array<string, mixed> $data
     *
     * @throws SceneNotFoundException
     */
    public function enter(string $scene, array $data = [], ?string $title = null): void
    {
        $this->conversation()->enter($scene, $data, $title);
    }

    public function back(): void
    {
        $this->conversation()->back();
    }

    public function leave(): void
    {
        $this->conversation()->leave();
    }

    public function clearHistory(): void
    {
        $this->session()->clearHistory();
    }

    public function canEnter(string $scene): bool
    {
        return $this->conversation()->canEnter($scene);
    }

    /**
     * Sends the question and starts describing the expected answer.
     */
    public function ask(View|string $view): Interaction
    {
        $this->reply($view);

        return new Interaction($this->session());
    }

    // -- request-scoped items ------------------------------------------------------------------

    /**
     * The route the runtime chose for this event, or null when a scene handled it.
     */
    public function getRoute(): ?Route
    {
        return $this->route;
    }

    /**
     * The argument of the matched command: "ref_abc123" for "/start ref_abc123". Empty when the
     * command carries no argument or the event was not routed to a command.
     */
    public function getCommandArgument(): string
    {
        if ($this->route === null || $this->route->getType() !== Route::COMMAND) {
            return '';
        }

        return Route::commandArgument($this->route->getPattern(), $this->getText());
    }

    /**
     * Records the route the runtime matched. Called by the Application before the tick.
     */
    public function bindRoute(?Route $route): void
    {
        $this->route = $route;
    }

    // -- localization --------------------------------------------------------------------------

    /**
     * The locale for this event, set by LocaleMiddleware. Null when nothing resolved one.
     */
    public function getLocale(): ?string
    {
        $locale = $this->get(self::LOCALE_KEY);

        return \is_string($locale) && $locale !== '' ? $locale : null;
    }

    public function setLocale(?string $locale): void
    {
        if ($locale === null || $locale === '') {
            $this->remove(self::LOCALE_KEY);

            return;
        }

        $this->set(self::LOCALE_KEY, $locale);
    }

    /**
     * Translates a message id in the locale of this event.
     *
     * @param array<string, string|int|float|bool|null> $parameters
     *
     * @throws LogicException When no translator is registered in the container
     */
    public function t(string $id, array $parameters = [], ?string $locale = null): string
    {
        if (!$this->container->has(TranslatorInterface::class)) {
            throw new LogicException(\sprintf(
                'No %s is registered in the container; register one to use Context::t().',
                TranslatorInterface::class,
            ));
        }

        $translator = $this->container->get(TranslatorInterface::class);

        if (!$translator instanceof TranslatorInterface) {
            throw new LogicException(\sprintf('The container entry for %s is not a translator.', TranslatorInterface::class));
        }

        return $translator->trans($id, $parameters, $locale ?? $this->getLocale());
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
        return \array_key_exists($key, $this->items);
    }

    public function remove(string $key): void
    {
        unset($this->items[$key]);
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record(string $name, array $data = []): void
    {
        $this->runtimeObserver->record(new RuntimeEvent($name, $this->getConversationId(), $data));
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
