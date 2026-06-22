<?php

declare(strict_types=1);

namespace ChatFlow\FSM;

use Automata\Contracts\AutomatonInterface;
use Automata\Contracts\ContextInterface;
use Automata\Core\CycleRequest;
use Automata\Core\CycleResponse;
use ChatFlow\Core\ClosureResolver;
use ChatFlow\Core\Context;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\FSMException;
use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\ValidationException;
use ChatFlow\FSM\Interop\ContextInput;
use ChatFlow\Storage\Session;
use ChatFlow\Validation\ValidationRegistry;
use ChatFlow\View\Action;
use ChatFlow\View\View;
use Closure;
use RuntimeException;

abstract class BaseScene implements AutomatonInterface
{
    protected Context $context;

    protected ?Session $session = null;

    public function process(CycleRequest $request): CycleResponse
    {
        try {
            $input = $request->getInput();
            if (!$input instanceof ContextInput) {
                throw new FSMException('Scene processing requires a ContextInput instance.');
            }

            $this->context = $input->getContext();
            $this->session = $this->context->getSession();

            if ($this->session === null) {
                throw new FSMException('Scene processing requires an active session.');
            }

            $skipSceneAction = (bool) $this->context->get('__chatflow_skip_scene_action', false);
            if ($this->context->isAction() && !$skipSceneAction && $this->handleSceneAction()) {
                return CycleResponse::none();
            }

            if ($this->isCommand()) {
                $this->invokeMethod('handle');

                return CycleResponse::none();
            }

            if ($this->session->hasInteraction()) {
                $this->handleInteraction();

                return CycleResponse::none();
            }

            $this->invokeMethod('handle');

            return CycleResponse::none();
        } catch (ContainerException|ValidationException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    public function getId(): string
    {
        return static::class;
    }

    public function onEnter(ContextInterface $context): void
    {
    }

    public function onLeave(ContextInterface $context): void
    {
    }

    abstract public function handle(Context $ctx): void;

    protected function getContext(): Context
    {
        return $this->context;
    }

    protected function getSession(): ?Session
    {
        return $this->session;
    }

    /**
     * @param array<string, mixed> $extraParams
     *
     * @throws ContainerException
     */
    protected function invokeMethod(string $method, array $extraParams = []): mixed
    {
        $parameters = array_merge($extraParams, [
            Context::class => $this->context,
            'ctx' => $this->context,
            'context' => $this->context,
            'params' => $extraParams,
        ]);

        return $this->context->getContainer()->call([$this, $method], $parameters);
    }

    /**
     * @throws LogicException
     * @throws ValidationException
     */
    protected function sceneAction(string $label, string|array|Closure $handler, array $payload = []): Action
    {
        $methodName = ClosureResolver::resolveName($handler);

        return new Action('scene:' . $methodName, $label, $payload);
    }

    /**
     * @throws FSMException
     */
    protected function ask(View|string $view): Interaction
    {
        $this->context->reply($view);

        if ($this->session === null) {
            throw new FSMException('Session is not initialized.');
        }

        return new Interaction($this->session);
    }

    protected function ack(?string $text = null, bool $error = false): void
    {
        $this->context->ack($text, $error);
    }

    public function getTitle(): string
    {
        $parts = explode('\\', static::class);

        return (string) end($parts);
    }

    /**
     * @return array<int, mixed>
     */
    public function getMiddlewares(): array
    {
        return [];
    }

    /**
     * @throws FSMException
     * @throws \ChatFlow\Exception\StorageException
     */
    protected function leave(): void
    {
        $this->context->leave();
    }

    private function isCommand(): bool
    {
        return str_starts_with($this->context->getText(), '/');
    }

    /**
     * @throws ContainerException
     */
    private function handleSceneAction(): bool
    {
        $actionId = $this->context->getActionId();
        if ($actionId === null || !str_starts_with($actionId, 'scene:')) {
            return false;
        }

        $methodName = substr($actionId, strlen('scene:'));
        if ($methodName === '' || !method_exists($this, $methodName) || !str_starts_with($methodName, 'on')) {
            return false;
        }

        $payload = $this->context->getActionPayload();
        $params = is_array($payload) ? $payload : [];
        $this->invokeMethod($methodName, $params);

        return true;
    }

    /**
     * @throws ContainerException
     * @throws ValidationException
     */
    private function handleInteraction(): void
    {
        if ($this->session === null) {
            return;
        }

        $config = $this->session->getInteraction();
        if ($config === null) {
            return;
        }

        foreach ($config['fallbacks'] as $fallback) {
            if ($this->matchesFallback($fallback['type'], $fallback['pattern'])) {
                $this->session->clearInteraction();
                $this->invokeMethod($fallback['handler']);

                return;
            }
        }

        $registry = $this->context->getContainer()->get(ValidationRegistry::class);
        if (!$registry instanceof ValidationRegistry) {
            throw new ValidationException('ValidationRegistry is not registered.');
        }

        $text = $this->context->getText();
        foreach ($config['validators'] as $validatorConfig) {
            $rule = $this->parseValidationRule($validatorConfig['rule']);
            $validator = $registry->get($rule['alias']);

            if (!$validator->validate($text, $rule['parameters'])) {
                $this->context->reply($validatorConfig['error'] ?? 'Invalid input');

                return;
            }
        }

        $this->session->clearInteraction();
        if ($config['handler'] !== null && $config['handler'] !== '') {
            $this->invokeMethod($config['handler']);
        }
    }

    /**
     * @param string|array<string> $pattern
     */
    private function matchesFallback(string $type, string|array $pattern): bool
    {
        if ($type === 'text') {
            $text = $this->context->getText();
            $patterns = is_array($pattern) ? $pattern : [$pattern];

            foreach ($patterns as $candidate) {
                if (str_starts_with($candidate, '/')) {
                    $result = @preg_match($candidate, $text);
                    if ($result !== false && $result > 0) {
                        return true;
                    }
                }

                if (mb_strtolower($text) === mb_strtolower($candidate)) {
                    return true;
                }
            }

            return false;
        }

        if ($type === 'media') {
            return $this->context->hasAttachment(is_string($pattern) ? $pattern : 'any');
        }

        return false;
    }

    /**
     * @return array{alias: string, parameters: array<string, mixed>}
     */
    private function parseValidationRule(string $rule): array
    {
        $separator = strpos($rule, ':');
        if ($separator === false) {
            return [
                'alias' => $rule,
                'parameters' => [],
            ];
        }

        $alias = substr($rule, 0, $separator);
        $rawParameter = substr($rule, $separator + 1);

        if ($alias === '' || $alias === 'regex') {
            return [
                'alias' => $alias === '' ? $rule : $alias,
                'parameters' => $alias === 'regex' ? ['pattern' => $rawParameter] : [],
            ];
        }

        return [
            'alias' => $alias,
            'parameters' => ['value' => $rawParameter],
        ];
    }
}
