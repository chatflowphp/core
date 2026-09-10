<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use Automata\Machine\CycleRequest;
use Automata\Machine\InputInterface;
use ChatFlow\Core\Context;
use ChatFlow\Exception\SceneException;
use ChatFlow\Routing\Route;

/**
 * The input of one tick: the inbound request and the route matched for it, if any.
 */
final class SceneInput implements InputInterface
{
    public function __construct(
        private readonly Context $context,
        private readonly ?Route $route = null,
    ) {}

    /**
     * @throws SceneException When the machine was ticked with a foreign input.
     */
    public static function fromRequest(CycleRequest $request): self
    {
        $input = $request->getInput();

        if (!$input instanceof self) {
            throw new SceneException(\sprintf(
                'Scenes expect %s as tick input, got %s.',
                self::class,
                $input::class,
            ));
        }

        return $input;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getRoute(): ?Route
    {
        return $this->route;
    }
}
