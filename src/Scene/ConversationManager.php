<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use Automata\Events\TransitionApplied;
use Automata\Exception\AutomataException;
use Automata\Machine\StateMachine;
use Automata\Snapshot\Session;
use Automata\State\StateRegistry;
use ChatFlow\Exception\SceneNotFoundException;
use ChatFlow\Observability\NullRuntimeObserver;
use ChatFlow\Observability\RuntimeEvent;
use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Routing\RouteDispatcher;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Storage\StorageInterface;
use ChatFlow\Validation\ValidationRegistry;
use Psr\Clock\ClockInterface;

/**
 * Builds the state machine of a conversation from the registered scenes and resumes it from storage.
 *
 * @phpstan-import-type PendingTransition from SceneContext
 */
final class ConversationManager
{
    private readonly SceneTransitions $transitions;

    private readonly ConversationStore $store;

    private readonly RouteDispatcher $routes;

    private readonly RuntimeObserverInterface $observer;

    public function __construct(
        private readonly SceneRegistry $scenes,
        private readonly ValidationRegistry $validation,
        ?StorageInterface $storage = null,
        ?SceneTransitions $transitions = null,
        ?int $sessionTtlSeconds = null,
        private readonly ?ClockInterface $clock = null,
        ?RuntimeObserverInterface $observer = null,
        ?RouteDispatcher $routes = null,
    ) {
        $this->transitions = $transitions ?? new SceneTransitions($scenes);
        $this->store = new ConversationStore($storage ?? new MemoryStorage(), $sessionTtlSeconds, $clock);
        $this->routes = $routes ?? new RouteDispatcher();
        $this->observer = $observer ?? new NullRuntimeObserver();
    }

    /**
     * Restores the conversation from storage, or starts it in the root scene.
     */
    public function resume(string $conversationId): Conversation
    {
        try {
            return $this->restore($conversationId);
        } catch (AutomataException $exception) {
            // The stored snapshot references a scene that no longer exists or cannot be applied:
            // start over instead of locking the user out of the bot.
            $this->observer->record(new RuntimeEvent('conversation.reset', $conversationId, [
                'reason' => $exception->getMessage(),
            ]));
            $this->store->delete($conversationId);

            return $this->restore($conversationId);
        }
    }

    /**
     * Schedules a scene entry from outside of a request: a scheduler, an admin action or another
     * chat. Nothing is sent now; the transition runs when the conversation receives its next
     * event, inside the runtime with a real request, so the scene's onEnter() can reply.
     *
     * @param string $scene Scene class or scene id.
     * @param array<string, mixed> $data Merged into the session when the transition runs.
     * @param bool $handleTrigger Whether the event that triggers the transition is then handled by
     *                            the new scene (true) or consumed as the trigger only (false).
     *
     * @throws SceneNotFoundException
     */
    public function enterLater(string $conversationId, string $scene, array $data = [], bool $handleTrigger = false, ?string $title = null): void
    {
        $conversation = $this->resume($conversationId);
        $conversation->getContext()->setPendingTransition([
            'action' => 'enter',
            'scene' => $this->scenes->resolveId($scene),
            'data' => $data,
            'title' => $title,
            'handleTrigger' => $handleTrigger,
        ]);
        $conversation->persist();
    }

    /**
     * Schedules leaving the current scene; applied on the conversation's next event.
     */
    public function leaveLater(string $conversationId, bool $handleTrigger = true): void
    {
        $conversation = $this->resume($conversationId);
        $conversation->getContext()->setPendingTransition([
            'action' => 'leave',
            'scene' => null,
            'data' => [],
            'title' => null,
            'handleTrigger' => $handleTrigger,
        ]);
        $conversation->persist();
    }

    /**
     * @return PendingTransition|null
     */
    public function getPending(string $conversationId): ?array
    {
        return $this->resume($conversationId)->getContext()->getPendingTransition();
    }

    public function clearPending(string $conversationId): void
    {
        $conversation = $this->resume($conversationId);

        if ($conversation->getContext()->hasPendingTransition()) {
            $conversation->getContext()->clearPendingTransition();
            $conversation->persist();
        }
    }

    public function exists(string $conversationId): bool
    {
        return $this->store->load($conversationId) !== null;
    }

    public function delete(string $conversationId): void
    {
        $this->store->delete($conversationId);
    }

    public function getScenes(): SceneRegistry
    {
        return $this->scenes;
    }

    public function getTransitions(): SceneTransitions
    {
        return $this->transitions;
    }

    public function getStore(): ConversationStore
    {
        return $this->store;
    }

    public function getStorage(): StorageInterface
    {
        return $this->store->getStorage();
    }

    private function restore(string $conversationId): Conversation
    {
        $context = new SceneContext();
        $machine = new StateMachine($context, null, $this->buildStates(), $this->transitions, $this->clock);
        $this->observe($machine, $conversationId);

        $session = Session::resume($this->store, $conversationId, static fn(): StateMachine => $machine, RootScene::ID);

        return new Conversation($session, $context, $this->scenes);
    }

    private function buildStates(): StateRegistry
    {
        $registry = new StateRegistry();
        $registry->register(new RootScene($this->routes));

        foreach ($this->scenes->all() as $scene) {
            $registry->register(new SceneState($scene, $this->routes, $this->validation));
        }

        return $registry;
    }

    private function observe(StateMachine $machine, string $conversationId): void
    {
        $machine->subscribe(TransitionApplied::class, function (TransitionApplied $event) use ($conversationId): void {
            if ($event->fromStateId !== RootScene::ID) {
                $this->observer->record(new RuntimeEvent('scene.left', $conversationId, [
                    'scene' => $event->fromStateId,
                    'to' => $event->toStateId,
                ]));
            }

            if ($event->toStateId !== RootScene::ID) {
                $this->observer->record(new RuntimeEvent('scene.entered', $conversationId, [
                    'scene' => $event->toStateId,
                    'from' => $event->fromStateId,
                ]));
            }
        });
    }
}
