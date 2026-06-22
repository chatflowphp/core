<?php

declare(strict_types=1);

namespace ChatFlow\Provider;

use ChatFlow\Config\ConfigInterface;
use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\DependencyException;
use ChatFlow\I18n\SymfonyTranslatorAdapter;
use ChatFlow\I18n\TranslatorInterface;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use RuntimeException;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;

class SymfonyTranslatorProvider implements ProviderInterface
{
    /**
     * Register translator service in container.
     *
     * @throws ContainerException
     */
    public function register(ContainerInterface $container): void
    {
        $container->set(TranslatorInterface::class, function (PsrContainerInterface $container) {
            try {
                if (!class_exists(Translator::class)) {
                    throw new DependencyException(
                        "Symfony Translation component is not installed.\n" .
                        'To use i18n, please run: composer require symfony/translation'
                    );
                }

                /** @var ConfigInterface $config */
                $config = $container->get(ConfigInterface::class);

                $locale = $config->getString('APP_LOCALE', 'en');

                $translator = new Translator($locale);
                $translator->addLoader('php', new PhpFileLoader());

                $basePath = $config->getBasePath();
                $langDir = $basePath . '/resources/lang';

                if (is_dir($langDir)) {
                    /** @var list<string> $files */
                    $files = glob("{$langDir}/*.php");
                    foreach ($files as $file) {
                        $domain = basename($file, '.php');
                        $translator->addResource('php', $file, $domain);
                    }
                }

                return new SymfonyTranslatorAdapter($translator);
            } catch (DependencyException $e) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }
        });
    }
}
