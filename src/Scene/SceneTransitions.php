<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use Automata\Context\ContextInterface;
use Automata\Machine\Transition\TransitionPolicyInterface;
use Automata\Machine\Transition\TransitionTable;
use Closure;

/**
 * Declares which scene transitions a flow allows.
 *
 * Until the first allow() call every transition is permitted. Once the table is restricted, a
 * transition must be declared (optionally with a guard) with these exceptions that are always
 * allowed: leaving to the root scene, and going back to the previous scene in history.
 *
 * Scenes are referenced by class name or scene id; "*" stands for any source scene.
 */
final class SceneTransitions implements TransitionPolicyInterface
{
    public const ANY = '*';

    /**
     * @var list<array{from: string, to: string, guard: Closure(SceneContext): bool|null}>
     */
    private array $rules = [];

    private ?TransitionTable $table = null;

    public function __construct(private readonly SceneRegistry $scenes) {}

    /**
     * @param (callable(SceneContext): bool)|null $guard
     */
    public function allow(string $from, string $to, ?callable $guard = null): self
    {
        $this->rules[] = ['from' => $from, 'to' => $to, 'guard' => $guard === null ? null : Closure::fromCallable($guard)];
        $this->table = null;

        return $this;
    }

    /**
     * Declarative form: `[From::class => [To::class, Other::class => fn (SceneContext $c): bool => ...]]`.
     *
     * @param array<string, array<array-key, string|callable(SceneContext): bool>> $definition
     */
    public function define(array $definition): self
    {
        foreach ($definition as $from => $targets) {
            foreach ($targets as $key => $value) {
                if (\is_string($value)) {
                    $this->allow($from, $value);

                    continue;
                }

                $this->allow($from, (string) $key, $value);
            }
        }

        return $this;
    }

    public function isRestricted(): bool
    {
        return $this->rules !== [];
    }

    public function isAllowed(string $fromStateId, string $toStateId, ContextInterface $context): bool
    {
        if ($toStateId === RootScene::ID || !$this->isRestricted()) {
            return true;
        }

        if ($context instanceof SceneContext && $context->isReturningTo($toStateId)) {
            return true;
        }

        $table = $this->table();

        return $table->isAllowed($fromStateId, $toStateId, $context)
            || $table->isAllowed(self::ANY, $toStateId, $context);
    }

    /**
     * Declared edges keyed by scene id, wildcard included.
     *
     * @return array<string, list<string>>
     */
    public function edges(): array
    {
        return $this->table()->edges();
    }

    public function toMermaid(): string
    {
        return $this->table()->toMermaid();
    }

    private function table(): TransitionTable
    {
        if ($this->table === null) {
            $table = new TransitionTable();

            foreach ($this->rules as $rule) {
                $guard = $rule['guard'];
                $table->allow(
                    $this->resolve($rule['from']),
                    $this->resolve($rule['to']),
                    $guard === null ? null : static fn(ContextInterface $context): bool => $context instanceof SceneContext && $guard($context),
                );
            }

            $this->table = $table;
        }

        return $this->table;
    }

    private function resolve(string $scene): string
    {
        if ($scene === self::ANY || $scene === RootScene::ID) {
            return $scene;
        }

        return $this->scenes->resolveId($scene);
    }
}
