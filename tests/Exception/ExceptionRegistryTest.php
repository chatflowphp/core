<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Exception;

use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\Exception\BotException;
use ChatFlow\Exception\ExceptionRegistry;
use ChatFlow\Exception\UserFriendlyException;
use ChatFlow\Tests\Support\FakePlatformAdapter;
use ChatFlow\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class ExceptionRegistryTest extends TestCase
{
    public function testTheMostSpecificHandlerWinsRegardlessOfRegistrationOrder(): void
    {
        $registry = new ExceptionRegistry();
        $trace = [];
        $registry->register(Throwable::class, static function () use (&$trace): void {
            $trace[] = 'throwable';
        });
        $registry->register(RuntimeException::class, static function () use (&$trace): void {
            $trace[] = 'runtime';
        });
        $registry->register(UserFriendlyException::class, static function () use (&$trace): void {
            $trace[] = 'friendly';
        });

        $registry->handle(new UnexpectedValueException('x'), null);
        $registry->handle(new \LogicException('y'), null);
        $registry->handle(new FriendlyFailure('z'), null);

        self::assertSame(['runtime', 'throwable', 'runtime'], $trace, 'parent classes win over interfaces');
    }

    public function testInterfaceHandlersLoseToClassHandlers(): void
    {
        $registry = new ExceptionRegistry();
        $trace = [];
        $registry->register(UserFriendlyException::class, static function () use (&$trace): void {
            $trace[] = 'friendly';
        });
        $registry->register(BotException::class, static function () use (&$trace): void {
            $trace[] = 'bot';
        });

        $registry->handle(new FriendlyFailure('z'), null);

        self::assertSame(['bot'], $trace);
    }

    public function testDefaultHandlerAcknowledgesFailedButtonPressesAsAlerts(): void
    {
        $adapter = new FakePlatformAdapter();
        $context = new Context(TestApp::event('c', actionId: 'menu:open'), $adapter, new Container());

        (new ExceptionRegistry())->handle(new RuntimeException('secret detail'), $context);

        $effects = $context->getOutboundEffects();
        self::assertCount(1, $effects);
        self::assertInstanceOf(\ChatFlow\Outbound\AckEffect::class, $effects[0]);
        self::assertSame('An internal error occurred. Please try again later.', $effects[0]->getText());
        self::assertTrue($effects[0]->isError());
    }

    public function testDefaultHandlerRepliesWithASafeMessage(): void
    {
        $adapter = new FakePlatformAdapter();
        $context = new Context(TestApp::event('c'), $adapter, new Container());

        (new ExceptionRegistry())->handle(new RuntimeException('secret detail'), $context);
        (new ExceptionRegistry())->handle(new FriendlyFailure('Try again later'), $context);
        (new ExceptionRegistry(debug: true))->handle(new RuntimeException('secret detail'), $context);

        $messages = array_map(static fn($effect) => $effect instanceof \ChatFlow\Outbound\ReplyEffect ? $effect->getView()->getText() : '', $context->getOutboundEffects());
        self::assertSame('An internal error occurred. Please try again later.', $messages[0]);
        self::assertSame('Try again later', $messages[1]);
        self::assertStringContainsString('[RuntimeException] secret detail at', $messages[2]);
    }
}

final class FriendlyFailure extends BotException implements UserFriendlyException {}
