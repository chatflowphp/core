<?php

declare(strict_types=1);

namespace ChatFlow\FSM;

use ChatFlow\Core\ClosureResolver;
use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\ValidationException;
use ChatFlow\Storage\Session;
use Closure;

class Interaction
{
    /** @var array{validators: array<int, array{rule: string, error: ?string}>, fallbacks: array<int, array{type: string, pattern: string|array<string>, handler: string}>, handler: ?string} */
    private array $config = [
        'validators' => [],
        'fallbacks' => [],
        'handler' => null,
    ];

    private bool $resolved = false;

    public function __construct(
        private readonly Session $session
    ) {
    }

    public static function make(Session $session): self
    {
        return new self($session);
    }

    /**
     * @param string|array<string>        $patterns
     * @param string|array<mixed>|Closure $handler
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function onText(string|array $patterns, string|array|Closure $handler): self
    {
        $this->config['fallbacks'][] = [
            'type' => 'text',
            'pattern' => $patterns,
            'handler' => ClosureResolver::resolveName($handler),
        ];

        return $this;
    }

    /**
     * @param string|array<mixed>|Closure $handler
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function onMedia(string $type, string|array|Closure $handler): self
    {
        $this->config['fallbacks'][] = [
            'type' => 'media',
            'pattern' => $type,
            'handler' => ClosureResolver::resolveName($handler),
        ];

        return $this;
    }

    public function validate(string $rule, ?string $error = null): self
    {
        $this->config['validators'][] = [
            'rule' => $rule,
            'error' => $error,
        ];

        return $this;
    }

    /**
     * @param string|array{0: object|string, 1: string}|Closure $handler
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function handle(string|array|Closure $handler): void
    {
        $this->config['handler'] = ClosureResolver::resolveName($handler);
        $this->session->setInteraction($this->config);
        $this->resolved = true;
    }

    public function __destruct()
    {
        if (!$this->resolved) {
            trigger_error(
                'ChatFlow: Interaction defined but not finalized. Call ->handle() to persist it.',
                E_USER_WARNING
            );
        }
    }
}
