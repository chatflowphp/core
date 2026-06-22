<?php

declare(strict_types=1);

namespace ChatFlow\Provider;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\ContainerException;

interface ProviderInterface
{
    /**
     * @throws ContainerException
     */
    public function register(ContainerInterface $container): void;
}
