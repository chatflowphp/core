<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use Automata\Machine\StateMachine;
use Automata\Machine\TickResult;
use Automata\Snapshot\Session;
use ChatFlow\Core\Context;
use ChatFlow\Exception\SceneNotFoundException;
use ChatFlow\Routing\Route;

/**
 * One conversation: the state machine restored for a conversation id, its context and history.
 *
 * @phpstan-import-type PendingTransition from SceneContext
 */
final class Conversation
{
    public function __construct(
        private readonly Session $session,
        private readonly SceneContext $context,
        private readonly SceneRegistry $scenes,
    ) {}

    public function getId(): string
    {
        return $this->session->getKey();
    }

    /**
     * Whether no snapshot existed for the conversation before this request.
     */
    public function isNew(): bool
    {
        return $this->session->isNew();
    }

    public function getMachine(): StateMachine
    {
        return $this->session->getMachine();
    }

    public function getContext(): SceneContext
    {
        return $this->context;
    }

    /**
     * Id of the active scene, RootScene::ID when no scene is active.
     */
    public function getCurrentScene(): string
    {
        return $this->getMachine()->getCurrentStateId() ?? RootScene::ID;
    }

    public function inScene(): bool
    {
        return $this->getCurrentScene() !== RootScene::ID;
    }

    /**
     * Runs one tick for the inbound request. The request is available to scene hooks for the
     * duration of the tick.
     */
    public function tick(Context $request, ?Route $route = null): TickResult
    {
        $this->context->bindRequest($request);

        try {
            return $this->session->tick(new SceneInput($request, $route));
        } finally {
            $this->context->unbindRequest();
        }
    }

    /**
     * Runs an operation with the request bound to the conversation, so that scene hooks can reply.
     * Used by the runtime for transitions that happen outside of a tick, such as pending entries.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @internal
     */
    public function withRequest(Context $request, callable $operation): mixed
    {
        $this->context->bindRequest($request);

        try {
            return $operation();
        } finally {
            $this->context->unbindRequest();
        }
    }

    /**
     * Returns the pending transition recorded by enterLater()/leaveLater() and removes it.
     *
     * @return PendingTransition|null
     *
     * @internal
     */
    public function takePendingTransition(): ?array
    {
        $pending = $this->context->getPendingTransition();

        if ($pending !== null) {
            $this->context->clearPendingTransition();
        }

        return $pending;
    }

    public function persist(): void
    {
        $this->session->persist();
    }

    public function end(): void
    {
        $this->session->end();
    }

    /**
     * Transitions into a scene. The current scene is pushed to history, the data is merged into the
     * context and the target's onEnter() runs. Entering the active scene runs its onEnter() again.
     *
     * @param string $scene Scene class or scene id.
     * @param array<string, mixed> $data
     *
     * @throws SceneNotFoundException
     */
    public function enter(string $scene, array $data = [], ?string $title = null): void
    {
        $target = $this->scenes->resolveId($scene);
        $current = $this->getCurrentScene();

        foreach ($data as $key => $value) {
            $this->context->set($key, $value);
        }

        if ($target === $current) {
            $this->scenes->get($target)->onEnter($this->context->request());

            return;
        }

        if ($current !== RootScene::ID) {
            $this->context->pushHistory($current, $title);
        }

        $this->getMachine()->transitionTo($target);
    }

    /**
     * Returns to the previous scene in history, or to the root scene when history is empty.
     */
    public function back(): void
    {
        $previous = $this->context->popHistory();
        $target = $previous !== null && $this->scenes->has($previous['scene']) ? $previous['scene'] : RootScene::ID;

        $this->context->markReturningTo($target);

        try {
            $this->getMachine()->transitionTo($target);
        } finally {
            $this->context->markReturningTo(null);
        }
    }

    /**
     * Ends the current flow: clears history and returns to the root scene.
     */
    public function leave(): void
    {
        $this->context->clearHistory();
        $this->getMachine()->transitionTo(RootScene::ID);
    }

    /**
     * @param string $scene Scene class or scene id.
     */
    public function canEnter(string $scene): bool
    {
        if (!$this->scenes->has($scene)) {
            return false;
        }

        return $this->getMachine()->canTransitionTo($this->scenes->resolveId($scene));
    }
}
