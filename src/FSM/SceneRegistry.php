<?php

declare(strict_types=1);

namespace ChatFlow\FSM;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\SceneNotFoundException;
use ChatFlow\Exception\ValidationException;

class SceneRegistry
{
    /** @var array<string, array{class: class-string<BaseScene>, label: ?string}> */
    private array $scenes = [];

    public function __construct(
        private readonly ?ContainerInterface $container = null
    ) {
    }

    /**
     * @param class-string<BaseScene> $sceneClass
     *
     * @throws ValidationException
     */
    public function register(string $sceneClass, ?string $label = null): void
    {
        if (!class_exists($sceneClass)) {
            throw new ValidationException("Scene class {$sceneClass} does not exist");
        }

        if (!is_subclass_of($sceneClass, BaseScene::class)) {
            throw new ValidationException(
                "Scene class {$sceneClass} must extend " . BaseScene::class
            );
        }

        $this->scenes[$sceneClass] = [
            'class' => $sceneClass,
            'label' => $label,
        ];
    }

    /**
     * @throws SceneNotFoundException
     * @throws ContainerException
     */
    public function get(string $sceneClass): BaseScene
    {
        if (!isset($this->scenes[$sceneClass])) {
            throw new SceneNotFoundException($sceneClass);
        }

        $actualClass = $this->scenes[$sceneClass]['class'];

        if ($this->container !== null) {
            if ($this->container->has($actualClass)) {
                /** @var BaseScene $scene */
                $scene = $this->container->get($actualClass);

                return $scene;
            }

            /** @var BaseScene $scene */
            $scene = $this->container->make($actualClass);

            return $scene;
        }

        return new $actualClass();
    }

    public function has(string $sceneClass): bool
    {
        return isset($this->scenes[$sceneClass]);
    }

    public function getLabel(string $sceneClass): ?string
    {
        return $this->scenes[$sceneClass]['label'] ?? null;
    }

    /**
     * @return array<string, array{class: class-string<BaseScene>, label: ?string}>
     */
    public function all(): array
    {
        return $this->scenes;
    }
}
