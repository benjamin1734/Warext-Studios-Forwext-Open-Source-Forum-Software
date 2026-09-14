<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Container;

use Forwext\Core\Container\Container;
use Forwext\Core\Container\Exception\CircularDependencyException;
use Forwext\Core\Container\Exception\OverrideNotAllowedException;
use Forwext\Core\Container\Exception\UnresolvableDependencyException;
use Forwext\Core\Container\ServiceLifetime;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testLazySingletonFactoryRunsOnlyWhenFirstResolved(): void
    {
        $container = new Container();
        $calls = 0;

        $container->lazy('clock', static function () use (&$calls): stdClass {
            ++$calls;
            return new stdClass();
        });

        self::assertSame(0, $calls);

        $first = $container->get('clock');
        $second = $container->get('clock');

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }

    public function testTransientFactoryCreatesNewInstanceForEachResolution(): void
    {
        $container = new Container();
        $container->bind('transient', static fn (): stdClass => new stdClass(), ServiceLifetime::Transient);

        self::assertNotSame($container->get('transient'), $container->get('transient'));
    }

    public function testConstructorAutowiringResolvesNestedClassDependency(): void
    {
        $container = new Container();
        $root = $container->get(AutowiredRoot::class);

        self::assertInstanceOf(AutowiredRoot::class, $root);
        self::assertInstanceOf(AutowiredLeaf::class, $root->leaf);
    }

    public function testCircularDependencyReportsResolutionPath(): void
    {
        $container = new Container();

        $this->expectException(CircularDependencyException::class);
        $this->expectExceptionMessage('CycleA');
        $this->expectExceptionMessage('CycleB');

        $container->get(CycleA::class);
    }

    public function testUnresolvableBuiltinDependencyFailsClosed(): void
    {
        $container = new Container();

        $this->expectException(UnresolvableDependencyException::class);
        $container->get(NeedsScalar::class);
    }

    public function testProductionContainerRejectsOverrides(): void
    {
        $container = new Container();
        $container->singleton('service', static fn (): stdClass => new stdClass());

        $this->expectException(OverrideNotAllowedException::class);
        $container->overrideInstance('service', new stdClass());
    }

    public function testTestingOverrideClearsPreviouslyResolvedSingleton(): void
    {
        $container = Container::forTesting();
        $container->singleton('service', static fn (): stdClass => new stdClass());

        $original = $container->get('service');
        $replacement = new stdClass();
        $container->overrideInstance('service', $replacement);

        self::assertNotSame($original, $container->get('service'));
        self::assertSame($replacement, $container->get('service'));
    }

    public function testTestingOverrideCanReplaceScalarInstanceWithoutTreatingItAsServiceId(): void
    {
        $container = Container::forTesting();
        $container->instance('value', 'original');
        $container->overrideInstance('value', 'replacement');

        self::assertSame('replacement', $container->get('value'));
    }
}

final class AutowiredLeaf
{
}

final class AutowiredRoot
{
    public function __construct(public readonly AutowiredLeaf $leaf)
    {
    }
}

final class CycleA
{
    public function __construct(public readonly CycleB $dependency)
    {
    }
}

final class CycleB
{
    public function __construct(public readonly CycleA $dependency)
    {
    }
}

final class NeedsScalar
{
    public function __construct(public readonly string $dsn)
    {
    }
}
