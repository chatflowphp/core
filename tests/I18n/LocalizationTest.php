<?php

declare(strict_types=1);

namespace ChatFlow\Tests\I18n;

use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\Exception\LogicException;
use ChatFlow\I18n\ArrayTranslator;
use ChatFlow\I18n\ChainLocaleResolver;
use ChatFlow\I18n\LocaleResolverInterface;
use ChatFlow\I18n\SessionLocaleResolver;
use ChatFlow\I18n\SymfonyTranslatorAdapter;
use ChatFlow\I18n\TranslatorInterface;
use ChatFlow\Middleware\LocaleMiddleware;
use ChatFlow\Tests\Support\FakePlatformAdapter;
use ChatFlow\Tests\Support\TestApp;
use ChatFlow\View\View;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface as SymfonyTranslatorInterface;

final class LocalizationTest extends TestCase
{
    public function testTheStoredPreferenceWinsOverThePlatformLanguage(): void
    {
        $adapter = new FakePlatformAdapter();
        $container = new Container();
        $container->set(TranslatorInterface::class, new ArrayTranslator([
            'en' => ['greeting' => 'Hello, {name}'],
            'ru' => ['greeting' => 'Привет, {name}'],
        ]));

        $application = TestApp::create($adapter, container: $container);
        $application->middleware([
            new LocaleMiddleware(new ChainLocaleResolver(new SessionLocaleResolver(), self::platformLocale('en'))),
        ]);

        $application->onCommand('start', static function (Context $ctx): void {
            $ctx->reply(View::text($ctx->t('greeting', ['name' => 'Alex'])));
        });
        $application->onCommand('ru', static function (Context $ctx): void {
            $ctx->session()->set('locale', 'ru');
            $ctx->reply(View::text($ctx->t('greeting', ['name' => 'Alex'])));
        });

        $application->handle(TestApp::event('conv-1', '/start'));
        self::assertSame('Hello, Alex', $adapter->replies[0]->getText(), 'Without a stored locale the platform language is used.');

        $application->handle(TestApp::event('conv-1', '/ru'));
        $application->handle(TestApp::event('conv-1', '/start'));
        self::assertSame('Привет, Alex', $adapter->replies[2]->getText(), 'The stored preference survives the next event.');
    }

    public function testTheLocaleIsRequestScopedAndPerConversation(): void
    {
        $adapter = new FakePlatformAdapter();
        $container = new Container();
        $container->set(TranslatorInterface::class, new ArrayTranslator(['en' => ['hi' => 'Hi'], 'ru' => ['hi' => 'Привет']]));

        $application = TestApp::create($adapter, container: $container);
        $application->middleware([new LocaleMiddleware(new SessionLocaleResolver())]);
        $application->onCommand('hi', static function (Context $ctx): void {
            $ctx->reply(View::text($ctx->t('hi') . '/' . ($ctx->getLocale() ?? 'none')));
        });

        $application->handle(TestApp::event('conv-ru', '/hi'));
        self::assertSame('Hi/none', $adapter->replies[0]->getText());

        $application->run('conv-ru', static function (Context $ctx): void {
            $ctx->session()->set('locale', 'ru');
        });
        $application->handle(TestApp::event('conv-ru', '/hi'));
        $application->handle(TestApp::event('conv-en', '/hi'));

        self::assertSame('Привет/ru', $adapter->replies[1]->getText());
        self::assertSame('Hi/none', $adapter->replies[2]->getText(), 'Another conversation keeps its own locale.');
    }

    public function testAnExplicitLocaleOverridesTheResolvedOne(): void
    {
        $adapter = new FakePlatformAdapter();
        $container = new Container();
        $container->set(TranslatorInterface::class, new ArrayTranslator(['en' => ['hi' => 'Hi'], 'ru' => ['hi' => 'Привет']]));

        $application = TestApp::create($adapter, container: $container);
        $application->middleware([new LocaleMiddleware(self::platformLocale('ru'))]);
        $application->onCommand('hi', static function (Context $ctx): void {
            $ctx->reply(View::text($ctx->t('hi', [], 'en')));
        });

        $application->handle(TestApp::event('conv-1', '/hi'));

        self::assertSame('Hi', $adapter->replies[0]->getText());
    }

    public function testTranslatingWithoutARegisteredTranslatorFailsWithAClearMessage(): void
    {
        $adapter = new FakePlatformAdapter();
        $application = TestApp::create($adapter);
        $caught = null;

        $application->setErrorHandler(static function (\Throwable $exception) use (&$caught): void {
            $caught = $exception;
        });
        $application->onCommand('hi', static function (Context $ctx): void {
            $ctx->reply(View::text($ctx->t('hi')));
        });

        $application->handle(TestApp::event('conv-1', '/hi'));

        self::assertInstanceOf(LogicException::class, $caught);
        self::assertStringContainsString('No ChatFlow\I18n\TranslatorInterface is registered', $caught->getMessage());
    }

    public function testSymfonyTranslatorsCanBeUsedThroughTheAdapter(): void
    {
        $symfony = new class implements SymfonyTranslatorInterface {
            /**
             * @param array<array-key, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return \sprintf('%s|%s|%s|%s', $id, json_encode($parameters), $domain ?? '-', $locale ?? '-');
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $translator = new SymfonyTranslatorAdapter($symfony, 'messages');

        self::assertSame('greeting|{"%name%":"Alex"}|messages|ru', $translator->trans('greeting', ['%name%' => 'Alex'], 'ru'));
    }

    private static function platformLocale(?string $locale): LocaleResolverInterface
    {
        return new class ($locale) implements LocaleResolverInterface {
            public function __construct(private readonly ?string $locale) {}

            public function resolve(Context $ctx): ?string
            {
                return $this->locale;
            }
        };
    }
}
