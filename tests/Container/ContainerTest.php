<?php

declare(strict_types=1);

namespace ChatFlow\Tests\Container;

use ChatFlow\Container\Adapter\PhpDiAdapter;
use ChatFlow\Container\Container;
use ChatFlow\Container\ContainerInterface;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\ServiceNotFoundException;
use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

final class ContainerTest extends TestCase
{
    /**
     * @param callable(): ContainerInterface $factory
     */
    #[DataProvider('containers')]
    public function testPersistentBindingsSurviveFlushAndScopedOnesDoNot(callable $factory): void
    {
        $container = $factory();
        $service = new Counter();

        $container->set('persistent', $service);
        $container->scoped('scoped', $service);

        self::assertTrue($container->has('persistent'));
        self::assertTrue($container->has('scoped'));

        $container->flush();

        self::assertSame($service, $container->get('persistent'));
        self::assertFalse($container->has('scoped'));
    }

    /**
     * @param callable(): ContainerInterface $factory
     */
    #[DataProvider('containers')]
    public function testCallInjectsScopedAndPersistentInstancesByType(callable $factory): void
    {
        $container = $factory();
        $counter = new Counter();
        $container->scoped(Counter::class, $counter);

        $seen = $container->call(static fn(Counter $c): Counter => $c);

        self::assertSame($counter, $seen);
    }

    /**
     * @param callable(): ContainerInterface $factory
     */
    #[DataProvider('containers')]
    public function testMakeBuildsFreshInstances(callable $factory): void
    {
        $container = $factory();

        $first = $container->make(Counter::class);
        $second = $container->make(Counter::class);

        self::assertInstanceOf(Counter::class, $first);
        self::assertNotSame($first, $second);
    }

    /**
     * @param callable(): ContainerInterface $factory
     */
    #[DataProvider('containers')]
    public function testUnknownServicesThrowAPsrNotFoundException(callable $factory): void
    {
        $container = $factory();

        try {
            $container->get('App\\Missing\\Service');
            self::fail('Expected an exception.');
        } catch (ServiceNotFoundException $e) {
            self::assertInstanceOf(NotFoundExceptionInterface::class, $e);
        }
    }

    /**
     * @return iterable<string, array{callable(): ContainerInterface}>
     */
    public static function containers(): iterable
    {
        yield 'hybrid' => [static fn(): ContainerInterface => new Container()];
        yield 'adapter' => [static fn(): ContainerInterface => new PhpDiAdapter((new ContainerBuilder())->build())];
    }

    public function testSingletonsAreSharedAndClassStringsAreAutowired(): void
    {
        $container = new Container();
        $container->singleton(Counter::class);
        $container->singleton('factory', static fn(): Counter => new Counter());

        self::assertSame($container->get(Counter::class), $container->get(Counter::class));
        self::assertInstanceOf(Counter::class, $container->get('factory'));

        $this->expectException(ContainerException::class);
        $container->singleton('late', Counter::class);
    }

    public function testDefinitionsCannotBeAddedAfterBuild(): void
    {
        $container = new Container();
        $container->get(Counter::class);

        $this->expectException(ContainerException::class);
        $container->addDefinitions(['x' => 1]);
    }

    public function testAdapterSingletonsResolveImmediately(): void
    {
        $adapter = new PhpDiAdapter((new ContainerBuilder())->build());
        $adapter->singleton(Counter::class);
        $adapter->singleton('value', 'just a string');
        $adapter->singleton('built', static fn(): Counter => new Counter());

        self::assertInstanceOf(Counter::class, $adapter->get(Counter::class));
        self::assertSame('just a string', $adapter->get('value'));
        self::assertInstanceOf(Counter::class, $adapter->get('built'));
    }
}

final class Counter
{
    public int $value = 0;
}
