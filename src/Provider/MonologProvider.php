<?php

declare(strict_types=1);

namespace ChatFlow\Provider;

use ChatFlow\Config\ConfigInterface;
use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\DependencyException;
use ChatFlow\Exception\StorageException;
use Elastic\Elasticsearch\ClientBuilder;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ElasticsearchHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MonologProvider implements ProviderInterface
{
    /**
     * Register logger service in container.
     *
     * @throws ContainerException
     */
    public function register(ContainerInterface $container): void
    {
        $container->set(LoggerInterface::class, function (PsrContainerInterface $container) {
            try {
                if (!class_exists(Logger::class)) {
                    throw new DependencyException(
                        "Monolog library is not installed.\n" .
                        'Please run: composer require monolog/monolog'
                    );
                }

                /** @var ConfigInterface $config */
                $config = $container->get(ConfigInterface::class);

                $logger = new Logger('bot');
                $driver = $config->getString('LOG_DRIVER', 'file');

                if ($driver === 'file' || $driver === 'stack') {
                    $logger->pushHandler($this->createFileHandler($container));
                }

                if ($driver === 'elastic' || $driver === 'stack') {
                    $logger->pushHandler($this->createElasticHandler($container));
                }

                return $logger;
            } catch (DependencyException|StorageException $e) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Create file handler for logging.
     *
     * @throws StorageException When log directory cannot be created
     */
    private function createFileHandler(PsrContainerInterface $container): StreamHandler
    {
        /** @var ConfigInterface $config */
        $config = $container->get(ConfigInterface::class);

        $basePath = $config->getBasePath();
        $logPathEnv = $config->getString('LOG_PATH', 'storage/logs/bot.log');

        // Check if the path is absolute (starts with /)
        if (str_starts_with($logPathEnv, '/')) {
            $logPath = $logPathEnv;
        } else {
            // Relative path - combine with base path
            $logPath = $basePath . '/' . $logPathEnv;
        }

        /** @var int $logLevelValue */
        $logLevelValue = $config->getInt('LOG_LEVEL', 100);
        $logLevel = Level::from($logLevelValue);

        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new StorageException(sprintf('Directory "%s" was not created', $dir));
            }
        }

        $handler = new StreamHandler($logPath, $logLevel);

        if ($config->getString('LOG_FORMAT', 'text') === 'json') {
            $handler->setFormatter(new JsonFormatter());
        } else {
            $output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
            $handler->setFormatter(new LineFormatter($output, 'Y-m-d H:i:s'));
        }

        return $handler;
    }

    /**
     * Create Elasticsearch handler for logging.
     *
     * @throws DependencyException When Elasticsearch client is not installed
     */
    private function createElasticHandler(PsrContainerInterface $container): ElasticsearchHandler
    {
        if (!class_exists(ClientBuilder::class)) {
            throw new DependencyException(
                "Elasticsearch client is not installed.\n" .
                'Please run: composer require elasticsearch/elasticsearch'
            );
        }

        /** @var ConfigInterface $config */
        $config = $container->get(ConfigInterface::class);

        $host = $config->getString('ELASTIC_HOST', 'http://localhost:9200');
        $user = $config->getString('ELASTIC_USER', '');
        $pass = $config->getString('ELASTIC_PASS', '');
        /** @var int $logLevelValue */
        $logLevelValue = $config->getInt('LOG_LEVEL', 100);
        $logLevel = Level::from($logLevelValue);

        $clientBuilder = ClientBuilder::create()->setHosts([$host]);

        if ($user !== '' && $pass !== '') {
            $clientBuilder->setBasicAuthentication($user, $pass);
        }

        $client = $clientBuilder->build();

        $options = [
            'index' => 'telegram_bot_logs_' . date('Y_m'),
            'type' => '_doc',
        ];

        return new ElasticsearchHandler($client, $options, $logLevel);
    }
}
