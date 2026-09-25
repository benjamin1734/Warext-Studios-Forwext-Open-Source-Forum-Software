<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Extension;

use Forwext\Core\Container\Container;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Event\AbstractDomainEvent;
use Forwext\Core\Domain\Event\DomainEvent;
use Forwext\Core\Domain\Event\DomainEventDispatcher;
use Forwext\Core\Extension\ExtensionGraphDiagnostics;
use Forwext\Core\Extension\ExtensionOwner;
use PHPUnit\Framework\TestCase;

final class ExtensionGraphDiagnosticsTest extends TestCase
{
    public function testSnapshotCombinesServiceAndEventExtensionRegistrations(): void
    {
        $container = new Container();
        $container->bind('fixture.service', static fn (): string => 'value');
        $container->decorate(
            'fixture.service',
            static fn (mixed $service, Container $container): mixed => $service,
            ExtensionOwner::addon('Acme/Decorator'),
            10,
        );

        $events = new DomainEventDispatcher();
        $events->listenTyped(
            ExtensionFixtureEvent::class,
            static function (DomainEvent $event): void {
            },
            5,
            ExtensionOwner::addon('Acme/Listener'),
        );

        $snapshot = ExtensionGraphDiagnostics::snapshot($container, $events);

        self::assertCount(1, $snapshot->services);
        self::assertCount(1, $snapshot->listeners);
        self::assertSame('addon:Acme/Decorator', $snapshot->services[0]->decorators[0]->owner);
        self::assertSame('addon:Acme/Listener', $snapshot->listeners[0]->owner);
        self::assertSame([], $snapshot->issues());
    }
}

final readonly class ExtensionFixtureEvent extends AbstractDomainEvent
{
    public function __construct()
    {
        parent::__construct(EntityId::fromInt(1));
    }

    public function eventName(): string
    {
        return 'extension.fixture';
    }
}
