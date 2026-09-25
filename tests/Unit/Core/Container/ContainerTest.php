<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Container;

use Forwext\Core\Container\Container;
use Forwext\Core\Container\Exception\CircularDependencyException;
use Forwext\Core\Container\Exception\ContainerException;
use Forwext\Core\Container\Exception\OverrideNotAllowedException;
use Forwext\Core\Container\Exception\UnresolvableDependencyException;
use Forwext\Core\Container\ServiceLifetime;
use Forwext\Core\Extension\ExtensionOwner;
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

    public function testServiceDecoratorsUsePriorityThenRegistrationOrderAndExposeDiagnostics(): void
    {
        $container = new Container();
        $container->bind('service', static fn (): string => 'base');

        $container->decorate(
            'service',
            static fn (mixed $service, Container $container): string => (string) $service . '|low',
            ExtensionOwner::addon('Acme/Low'),
            -10,
        );
        $container->decorate(
            'service',
            static fn (mixed $service, Container $container): string => (string) $service . '|high',
            ExtensionOwner::addon('Acme/High'),
            20,
        );

        self::assertSame('base|high|low', $container->get('service'));

        $diagnostics = $container->extensionDiagnostics();
        self::assertCount(1, $diagnostics);
        self::assertSame('service', $diagnostics[0]->serviceId);
        self::assertSame(['addon:Acme/High', 'addon:Acme/Low'], array_map(
            static fn ($decorator): string => $decorator->owner,
            $diagnostics[0]->decorators,
        ));
        self::assertSame([], $diagnostics[0]->issues);
    }

    public function testExtensionBindingRecordsOwnerAndDuplicateBindingFailsClosed(): void
    {
        $container = new Container();
        $container->bindExtension(
            'extension.service',
            ExtensionOwner::addon('Acme/Binding'),
            static fn (): string => 'extension',
            ServiceLifetime::Singleton,
        );

        self::assertSame('extension', $container->get('extension.service'));
        $diagnostic = array_values(array_filter(
            $container->extensionDiagnostics(),
            static fn ($item): bool => $item->serviceId === 'extension.service',
        ))[0];
        self::assertSame('addon:Acme/Binding', $diagnostic->bindingOwner);
        self::assertSame(ServiceLifetime::Singleton, $diagnostic->lifetime);

        $this->expectException(ContainerException::class);
        $container->bindExtension(
            'extension.service',
            ExtensionOwner::addon('Acme/Other'),
            static fn (): string => 'conflict',
        );
    }

    public function testSameExtensionOwnerCannotDecorateSameServiceTwice(): void
    {
        $container = new Container();
        $container->bind('service', static fn (): string => 'base');
        $owner = ExtensionOwner::addon('Acme/Demo');
        $container->decorate(
            'service',
            static fn (mixed $service, Container $container): mixed => $service,
            $owner,
        );

        $this->expectException(ContainerException::class);
        $container->decorate(
            'service',
            static fn (mixed $service, Container $container): mixed => $service,
            $owner,
        );
    }

    public function testDecoratorResolutionParticipatesInCircularDependencyDetection(): void
    {
        $container = new Container();
        $container->bind('service', static fn (): string => 'base');
        $container->decorate(
            'service',
            static function (mixed $service, Container $container): mixed {
                $container->get('service');

                return $service;
            },
            ExtensionOwner::addon('Acme/Cycle'),
        );

        $this->expectException(CircularDependencyException::class);
        $container->get('service');
    }

    public function testDecoratorMustPreserveDeclaredServiceType(): void
    {
        $container = new Container();
        $container->bind(DecoratedContract::class, DecoratedImplementation::class);
        $container->decorate(
            DecoratedContract::class,
            static fn (mixed $service, Container $container): stdClass => new stdClass(),
            ExtensionOwner::addon('Acme/InvalidDecorator'),
        );

        $this->expectException(ContainerException::class);
        $container->get(DecoratedContract::class);
    }

    public function testExtensionDiagnosticsReportDecoratorWithoutResolvableBase(): void
    {
        $container = new Container();
        $container->decorate(
            'missing.extension.service',
            static fn (mixed $service, Container $container): mixed => $service,
            ExtensionOwner::addon('Acme/Missing'),
        );

        $diagnostics = $container->extensionDiagnostics();
        self::assertCount(1, $diagnostics);
        self::assertSame('addon:Acme/Missing', $diagnostics[0]->decorators[0]->owner);
        self::assertContains('decorator_without_resolvable_base', $diagnostics[0]->issues);
        self::assertNull($diagnostics[0]->bindingOwner);
    }

    public function testExtensionDiagnosticsReportsBindingAliasCycleWithoutResolvingIt(): void
    {
        $container = new Container();
        $container->bind('service.a', 'service.b');
        $container->bind('service.b', 'service.a');

        $issues = [];
        foreach ($container->extensionDiagnostics() as $diagnostic) {
            array_push($issues, ...$diagnostic->issues);
        }

        self::assertTrue((bool) array_filter(
            $issues,
            static fn (string $issue): bool => str_starts_with($issue, 'binding_cycle:'),
        ));
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


interface DecoratedContract
{
}

final class DecoratedImplementation implements DecoratedContract
{
}
