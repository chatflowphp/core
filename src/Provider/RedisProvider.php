<?php

declare(strict_types=1);

namespace ChatFlow\Provider;

use ChatFlow\Config\ConfigInterface;
use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\DependencyException;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Redis;
use RuntimeException;

class RedisProvider implements ProviderInterface
{
    /**
     * Register Redis service in container.
     *
     * @throws ContainerException
     */
    public function register(ContainerInterface $container): void
    {
        $container->set(Redis::class, function (PsrContainerInterface $container) {
            try {
                if (!extension_loaded('redis')) {
                    throw new DependencyException(
                        "The 'redis' PHP extension is not loaded.\n" .
                        'Please install it or remove RedisProvider.'
                    );
                }

                /** @var ConfigInterface $config */
                $config = $container->get(ConfigInterface::class);

                $redis = new Redis();

                $host = $config->getString('REDIS_HOST', '127.0.0.1');
                $port = $config->getInt('REDIS_PORT', 6379);
                $password = $config->getString('REDIS_PASSWORD', '');

                $connected = $redis->connect($host, $port);
                if ($connected === false) {
                    throw new DependencyException("Could not connect to Redis at {$host}:{$port}");
                }

                if ($password !== '') {
                    $authResult = $redis->auth($password);
                    if ($authResult === false) {
                        throw new DependencyException('Redis authentication failed.');
                    }
                }

                return $redis;
            } catch (DependencyException $e) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }
        });
    }
}
