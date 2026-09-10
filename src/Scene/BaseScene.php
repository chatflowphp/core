<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use ChatFlow\Core\ClosureResolver;
use ChatFlow\Core\Context;
use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\ValidationException;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\View\Action;
use Closure;

/**
 * A scene is one state of the conversation: a screen or a dialog step the user is currently in.
 *
 * Scenes are stateless services. One instance serves every conversation, so keep per-user data in
 * `$ctx->session()` and never in properties. The runtime calls:
 *
 * - onEnter() when the conversation transitions into the scene (defaults to handle());
 * - handle() for input the scene did not claim otherwise, typically to render the screen again;
 * - public `on*` methods for scene actions produced by sceneAction();
 * - interaction handlers registered through `$ctx->ask()`;
 * - onLeave() when the conversation transitions away.
 */
abstract class BaseScene
{
    public const ACTION_PREFIX = 'scene:';

    /**
     * Stable identifier stored in snapshots and used in transition tables. Defaults to the class
     * name; override it to keep stored conversations valid when the class moves.
     */
    public function getId(): string
    {
        return static::class;
    }

    public function getTitle(): string
    {
        $separator = strrpos(static::class, '\\');

        return $separator === false ? static::class : substr(static::class, $separator + 1);
    }

    /**
     * Middleware that wraps every tick handled while this scene is active.
     *
     * @return list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    public function getMiddlewares(): array
    {
        return [];
    }

    /**
     * Whether global routes (commands by default) may interrupt this scene. Return false to make the
     * scene consume every input, including commands.
     */
    public function allowsGlobalRoutes(): bool
    {
        return true;
    }

    /**
     * Runs when the conversation enters the scene, inside the same tick as the transition.
     */
    public function onEnter(Context $ctx): void
    {
        $this->handle($ctx);
    }

    /**
     * Runs when the conversation leaves the scene. The pending interaction is already cleared.
     */
    public function onLeave(Context $ctx): void {}

    /**
     * Renders the scene or asks its question. Also receives input that neither a scene action nor
     * a pending interaction claimed.
     */
    abstract public function handle(Context $ctx): void;

    /**
     * Builds an action button that calls a public `on*` method of this scene when pressed.
     *
     * @param string|array{0: object|string, 1: string}|Closure $handler
     * @param array<string, mixed> $payload Passed to the method as named parameters and as `$params`.
     *
     * @throws LogicException
     * @throws ValidationException
     */
    protected function sceneAction(string $label, string|array|Closure $handler, array $payload = []): Action
    {
        return new Action(self::ACTION_PREFIX . ClosureResolver::resolveName($handler), $label, $payload === [] ? null : $payload);
    }
}
