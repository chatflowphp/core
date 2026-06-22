<?php

declare(strict_types=1);

namespace ChatFlow\FSM\Interop;

use Automata\Contracts\ContextInterface;
use ChatFlow\Storage\Session;

final class SessionContext implements ContextInterface
{
    public function __construct(
        private readonly Session $session
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->session->all();
    }

    /**
     * @param array<string, mixed> $state
     */
    public function setState(array $state): void
    {
        $this->session->clearData();

        foreach ($state as $key => $value) {
            $this->session->set($key, $value);
        }
    }
}
