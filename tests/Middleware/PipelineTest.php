<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Middleware;

use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\LogicException;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Middleware\Pipeline;
use ChatFlow\Tests\Support\FakePlatformAdapter;
use ChatFlow\Tests\Support\HookLog;
use ChatFlow\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    public function testMiddlewareWrapsTheDestinationInOrder(): void
    {
        $context = new Context(TestApp::event('c'), new FakePlatformAdapter(), new Container());
        $trace = new HookLog();
        $make = static fn(string $name): MiddlewareInterface => new class ($name, $trace) implements MiddlewareInterface {
            public function __construct(private readonly string $name, private readonly HookLog $trace) {}

            public function process(Context $ctx, callable $next): mixed
            {
                $this->trace->add($this->name . ':before');
                $result = $next($ctx);
                $this->trace->add($this->name . ':after');

                return $result;
            }
        };

        $result = (new Pipeline())
            ->send($context)
            ->through([$make('a'), $make('b')])
            ->then(static function (Context $ctx) use ($trace): string {
                $trace->add('destination');

                return 'done';
            });

        self::assertSame('done', $result);
        self::assertSame(['a:before', 'b:before', 'destination', 'b:after', 'a:after'], $trace->all());
    }

    public function testClassNamesAreResolvedThroughTheContainer(): void
    {
        $container = new Container();
        $context = new Context(TestApp::event('c'), new FakePlatformAdapter(), $container);

        $result = (new Pipeline($container))
            ->send($context)
            ->through([TaggingMiddleware::class])
            ->then(static fn(Context $ctx): mixed => $ctx->get('tag'));

        self::assertSame('tagged', $result);
    }

    public function testUnresolvableMiddlewareIsReported(): void
    {
        $context = new Context(TestApp::event('c'), new FakePlatformAdapter(), new Container());

        $this->expectException(ContainerException::class);

        /** @phpstan-ignore argument.type */
        (new Pipeline())->send($context)->through(['App\\Missing\\Middleware'])->then(static fn(): null => null);
    }

    public function testContextMustBeSentFirst(): void
    {
        $this->expectException(LogicException::class);

        (new Pipeline())->then(static fn(): null => null);
    }
}

final class TaggingMiddleware implements MiddlewareInterface
{
    public function process(Context $ctx, callable $next): mixed
    {
        $ctx->set('tag', 'tagged');

        return $next($ctx);
    }
}
