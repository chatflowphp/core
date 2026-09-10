<?php

declare(strict_types=1);

namespace ChatFlow\Scene;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\SceneException;
use ChatFlow\Exception\SceneNotFoundException;

/**
 * Registered scene classes and their instances. Scenes are built once through the container
 * (`make()`, so container definitions apply) and reused for every conversation.
 */
final class SceneRegistry
{
    /**
     * @var array<class-string<BaseScene>, string|null>
     */
    private array $labels = [];

    /**
     * @var array<class-string<BaseScene>, BaseScene>
     */
    private array $instances = [];

    /**
     * @var array<string, class-string<BaseScene>>
     */
    private array $classesById = [];

    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * @param class-string<BaseScene> $sceneClass
     *
     * @throws SceneException
     */
    public function register(string $sceneClass, ?string $label = null): void
    {
        if (!class_exists($sceneClass)) {
            throw new SceneException(\sprintf('Scene class "%s" does not exist.', $sceneClass));
        }

        if (!is_subclass_of($sceneClass, BaseScene::class)) {
            throw new SceneException(\sprintf('Scene class "%s" must extend %s.', $sceneClass, BaseScene::class));
        }

        $this->labels[$sceneClass] = $label;
    }

    /**
     * @param string $scene Scene class or scene id.
     */
    public function has(string $scene): bool
    {
        return \array_key_exists($scene, $this->labels) || $this->classById($scene) !== null;
    }

    /**
     * @return list<class-string<BaseScene>>
     */
    public function classes(): array
    {
        return array_keys($this->labels);
    }

    /**
     * @param class-string<BaseScene> $sceneClass
     */
    public function getLabel(string $sceneClass): ?string
    {
        return $this->labels[$sceneClass] ?? null;
    }

    /**
     * @param string $scene Scene class or scene id.
     *
     * @throws SceneNotFoundException
     */
    public function get(string $scene): BaseScene
    {
        if (\array_key_exists($scene, $this->labels)) {
            return $this->instance($scene);
        }

        $class = $this->classById($scene);

        if ($class === null) {
            throw new SceneNotFoundException($scene);
        }

        return $this->instance($class);
    }

    /**
     * @param string $scene Scene class or scene id.
     *
     * @throws SceneNotFoundException
     */
    public function resolveId(string $scene): string
    {
        return $this->get($scene)->getId();
    }

    /**
     * Every registered scene keyed by id.
     *
     * @return array<string, BaseScene>
     */
    public function all(): array
    {
        $scenes = [];

        foreach ($this->classes() as $class) {
            $scene = $this->instance($class);
            $scenes[$scene->getId()] = $scene;
        }

        return $scenes;
    }

    /**
     * @param class-string<BaseScene> $class
     */
    private function instance(string $class): BaseScene
    {
        if (!isset($this->instances[$class])) {
            $scene = $this->container->make($class);

            if (!$scene instanceof BaseScene) {
                throw new SceneException(\sprintf('Container returned %s for scene "%s".', get_debug_type($scene), $class));
            }

            $this->instances[$class] = $scene;
            $this->classesById[$scene->getId()] = $class;
        }

        return $this->instances[$class];
    }

    /**
     * @return class-string<BaseScene>|null
     */
    private function classById(string $id): ?string
    {
        if (isset($this->classesById[$id])) {
            return $this->classesById[$id];
        }

        foreach ($this->classes() as $class) {
            if ($this->instance($class)->getId() === $id) {
                return $class;
            }
        }

        return null;
    }
}
