<?php

declare(strict_types=1);

namespace ChatFlow\Contracts;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Core\Context;

interface RuntimeDependencyBinderInterface
{
    public function bindRuntimeDependencies(ContainerInterface $container, Context $context): void;
}
