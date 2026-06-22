<?php

declare(strict_types=1);

namespace ChatFlow\FSM;

use Automata\Core\Orchestrator;
use ChatFlow\Core\Context;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\SceneNotFoundException;
use ChatFlow\Exception\StorageException;
use ChatFlow\FSM\Interop\ContextInput;
use ChatFlow\FSM\Interop\SessionContext;
use ChatFlow\Storage\Session;
use ChatFlow\Storage\StorageInterface;
use InvalidArgumentException;

class StateManager
{
    public function __construct(
        private readonly SceneRegistry $registry,
        private readonly StorageInterface $storage,
        private readonly ?int $sessionTtlSeconds = null,
    ) {
        if ($this->sessionTtlSeconds !== null && $this->sessionTtlSeconds <= 0) {
            throw new InvalidArgumentException('Session TTL must be a positive integer.');
        }
    }

    /**
     * @throws StorageException
     */
    public function loadSession(string $conversationId): Session
    {
        $data = $this->storage->get($conversationId);
        if ($data === null) {
            return new Session($conversationId, time());
        }

        $session = Session::fromArray($data);
        if ($this->isExpired($session)) {
            $this->storage->delete($conversationId);

            return new Session($conversationId, time());
        }

        return $session;
    }

    /**
     * @throws StorageException
     */
    public function saveSession(Session $session): void
    {
        $this->storage->save($session->getConversationId(), $session->toArray());
    }

    /**
     * @throws StorageException
     */
    public function deleteSession(string $conversationId): void
    {
        $this->storage->delete($conversationId);
    }

    /**
     * @throws StorageException
     */
    public function applyPendingTransitions(Context $context): void
    {
        $session = $context->getSession();
        if ($session === null) {
            return;
        }

        $dirty = false;

        $pendingScene = $session->getPendingScene();
        if ($pendingScene !== null) {
            $session->setCurrentScene($pendingScene);
            $session->clearPendingScene();
            $session->clearInteraction();

            foreach ($session->getPendingSceneData() as $key => $value) {
                $session->set($key, $value);
            }

            $session->clearPendingSceneData();
            $dirty = true;
        }

        if ($session->isExitPending()) {
            $session->setCurrentScene(null);
            $session->clearPendingExit();
            $session->clearInteraction();
            $dirty = true;
        }

        if ($dirty) {
            $this->saveSession($session);
        }
    }

    /**
     * @throws StorageException
     * @throws SceneNotFoundException
     * @throws ContainerException
     */
    public function processScene(Context $context, bool $skipCurrentAction = false, ?BaseScene $scene = null): bool
    {
        $conversationId = $context->getConversationId();
        $session = $context->getSession() ?? $this->loadSession($conversationId);
        $context->setSession($session);

        $this->applyPendingTransitions($context);

        $currentScene = $session->getCurrentScene();
        if ($currentScene === null) {
            return false;
        }

        $scene ??= $this->registry->get($currentScene);

        $orchestrator = new Orchestrator(new SessionContext($session));
        $orchestrator->registerAutomaton($scene);
        $orchestrator->activate($currentScene);

        $originalSkipFlag = $context->get('__chatflow_skip_scene_action', false);
        if ($skipCurrentAction) {
            $context->set('__chatflow_skip_scene_action', true);
        }

        try {
            $orchestrator->tick(new ContextInput($context));
        } finally {
            if ($skipCurrentAction) {
                $context->set('__chatflow_skip_scene_action', $originalSkipFlag);
            }
        }

        $this->saveSession($session);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws StorageException
     */
    public function enterScene(string $conversationId, string $sceneClass, array $data = []): void
    {
        $session = $this->loadSession($conversationId);
        $session->requestScene($sceneClass, $data);
        $this->saveSession($session);
    }

    /**
     * @throws StorageException
     */
    public function exitScene(string $conversationId): void
    {
        $session = $this->loadSession($conversationId);
        $session->setCurrentScene(null);
        $session->clearPendingScene();
        $session->clearPendingSceneData();
        $session->clearPendingExit();
        $session->clearInteraction();
        $this->saveSession($session);
    }

    public function getRegistry(): SceneRegistry
    {
        return $this->registry;
    }

    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    private function isExpired(Session $session): bool
    {
        return $this->sessionTtlSeconds !== null
            && (time() - $session->getUpdatedAt()) > $this->sessionTtlSeconds;
    }
}
