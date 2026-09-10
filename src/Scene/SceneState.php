<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use Automata\Context\ContextInterface;
use Automata\Machine\CycleRequest;
use Automata\Machine\CycleResponse;
use Automata\State\StateInterface;
use ChatFlow\Core\Context;
use ChatFlow\Exception\SceneException;
use ChatFlow\Routing\RouteDispatcher;
use ChatFlow\Scene\Events\RouteHandled;
use ChatFlow\Scene\Events\SceneHandled;
use ChatFlow\Validation\ValidationRegistry;
use ReflectionMethod;

/**
 * Adapts a BaseScene to the automata state contract and implements the scene input protocol:
 * global routes, scene actions, pending interactions and the handle() fallback.
 *
 * @internal
 *
 * @phpstan-import-type InteractionConfig from Interaction
 */
final class SceneState implements StateInterface
{
    public function __construct(
        private readonly BaseScene $scene,
        private readonly RouteDispatcher $routes,
        private readonly ValidationRegistry $validation,
    ) {}

    public function getScene(): BaseScene
    {
        return $this->scene;
    }

    public function getId(): string
    {
        return $this->scene->getId();
    }

    public function onEnter(ContextInterface $context): CycleResponse
    {
        $this->scene->onEnter(self::sceneContext($context)->request());

        return CycleResponse::none();
    }

    public function process(CycleRequest $request): CycleResponse
    {
        $input = SceneInput::fromRequest($request);
        $ctx = $input->getContext();
        $route = $input->getRoute();

        if ($route !== null && $route->isGlobal() && $this->scene->allowsGlobalRoutes()) {
            $this->routes->dispatch($route, $ctx);

            return CycleResponse::fromEvent(new RouteHandled($route->getType(), $route->getPattern(), true, $this->getId()));
        }

        if ($ctx->isAction() && $this->handleSceneAction($ctx)) {
            return CycleResponse::fromEvent(new SceneHandled($this->getId(), SceneHandled::ACTION));
        }

        $interaction = $ctx->session()->getInteraction();

        if ($interaction !== null) {
            $this->handleInteraction($ctx, $interaction);

            return CycleResponse::fromEvent(new SceneHandled($this->getId(), SceneHandled::INTERACTION));
        }

        $this->scene->handle($ctx);

        return CycleResponse::fromEvent(new SceneHandled($this->getId(), SceneHandled::HANDLE));
    }

    public function onLeave(ContextInterface $context): void
    {
        $sceneContext = self::sceneContext($context);
        $sceneContext->clearInteraction();
        $this->scene->onLeave($sceneContext->request());
    }

    private function handleSceneAction(Context $ctx): bool
    {
        $actionId = $ctx->getActionId();

        if ($actionId === null || !str_starts_with($actionId, BaseScene::ACTION_PREFIX)) {
            return false;
        }

        $method = substr($actionId, \strlen(BaseScene::ACTION_PREFIX));

        if ($method === '' || !str_starts_with($method, 'on') || !method_exists($this->scene, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($this->scene, $method);

        if (!$reflection->isPublic() || $reflection->isStatic()) {
            return false;
        }

        $payload = $ctx->getActionPayload();
        $params = [];

        if (\is_array($payload)) {
            foreach ($payload as $key => $value) {
                if (\is_string($key)) {
                    $params[$key] = $value;
                }
            }
        }

        $this->invoke($ctx, $method, $params);

        return true;
    }

    /**
     * @param InteractionConfig $config
     */
    private function handleInteraction(Context $ctx, array $config): void
    {
        $session = $ctx->session();

        foreach ($config['fallbacks'] as $fallback) {
            if ($this->matchesFallback($ctx, $fallback['type'], $fallback['pattern'])) {
                $session->clearInteraction();
                $this->invoke($ctx, $fallback['handler']);

                return;
            }
        }

        $text = $ctx->getText();

        foreach ($config['validators'] as $validator) {
            $rule = self::parseValidationRule($validator['rule']);

            if (!$this->validation->get($rule['alias'])->validate($text, $rule['parameters'])) {
                $ctx->reply($validator['error'] ?? 'Invalid input');

                return;
            }
        }

        $session->clearInteraction();

        if ($config['handler'] !== null && $config['handler'] !== '') {
            $this->invoke($ctx, $config['handler']);
        }
    }

    /**
     * @param string|list<string> $pattern
     */
    private function matchesFallback(Context $ctx, string $type, string|array $pattern): bool
    {
        if ($type === Interaction::TYPE_TEXT) {
            $text = $ctx->getText();

            foreach (\is_array($pattern) ? $pattern : [$pattern] as $candidate) {
                if (str_starts_with($candidate, '/') && @preg_match($candidate, $text) === 1) {
                    return true;
                }

                if (mb_strtolower($text) === mb_strtolower($candidate)) {
                    return true;
                }
            }

            return false;
        }

        if ($type === Interaction::TYPE_MEDIA) {
            return $ctx->hasAttachment(\is_string($pattern) ? $pattern : 'any');
        }

        return false;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function invoke(Context $ctx, string $method, array $params = []): void
    {
        $callable = [$this->scene, $method];

        if (!\is_callable($callable)) {
            throw new SceneException(\sprintf(
                'Scene "%s" has no public method "%s" to handle the input.',
                $this->getId(),
                $method,
            ));
        }

        $ctx->getContainer()->call($callable, array_merge($params, [
            Context::class => $ctx,
            'ctx' => $ctx,
            'context' => $ctx,
            'params' => $params,
        ]));
    }

    /**
     * @return array{alias: string, parameters: array<string, mixed>}
     */
    private static function parseValidationRule(string $rule): array
    {
        $separator = strpos($rule, ':');

        if ($separator === false || $separator === 0) {
            return ['alias' => $rule, 'parameters' => []];
        }

        $alias = substr($rule, 0, $separator);
        $parameter = substr($rule, $separator + 1);

        if ($alias === 'regex') {
            return ['alias' => $alias, 'parameters' => ['pattern' => $parameter]];
        }

        return ['alias' => $alias, 'parameters' => ['value' => $parameter]];
    }

    private static function sceneContext(ContextInterface $context): SceneContext
    {
        if (!$context instanceof SceneContext) {
            throw new SceneException(\sprintf('Scenes require %s as machine context, got %s.', SceneContext::class, $context::class));
        }

        return $context;
    }
}
