<?php

declare(strict_types=1);

namespace ChatFlow\Provider;

use ChatFlow\Config\ConfigInterface;
use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\DependencyException;
use PDO;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use RuntimeException;

class PdoProvider implements ProviderInterface
{
    /**
     * Register PDO service in container.
     *
     * @throws ContainerException
     */
    public function register(ContainerInterface $container): void
    {
        $container->set(PDO::class, function (PsrContainerInterface $container) {
            try {
                if (!extension_loaded('pdo')) {
                    throw new DependencyException(
                        "The 'pdo' PHP extension is not loaded.\n" .
                        'Please install PDO for your database.'
                    );
                }

                /** @var ConfigInterface $config */
                $config = $container->get(ConfigInterface::class);

                $driver = $config->getString('DB_DRIVER', 'mysql');

                if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
                    throw new DependencyException(
                        "The PDO driver '{$driver}' is not installed/enabled.\n" .
                        'Available drivers: ' . implode(', ', PDO::getAvailableDrivers())
                    );
                }

                $host = $config->getString('DB_HOST', '127.0.0.1');
                $port = $config->getString('DB_PORT', '3306');
                $database = $config->getString('DB_NAME', '');
                $username = $config->getString('DB_USER', '');
                $password = $config->getString('DB_PASS', '');
                $charset = $config->getString('DB_CHARSET', 'utf8mb4');

                $dsn = "{$driver}:host={$host};port={$port};dbname={$database};charset={$charset}";

                return new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (DependencyException $e) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }
        });
    }
}
