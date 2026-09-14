<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain;

use Forwext\Core\Domain\Entity\Entity;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Event\AbstractDomainEvent;
use Forwext\Core\Domain\Event\DomainEvent;
use Forwext\Core\Domain\Event\DomainEventDispatcher;
use Forwext\Core\Domain\Event\RecordsDomainEvents;
use PHPUnit\Framework\TestCase;

final class DomainEventTest extends TestCase
{
    public function testAggregateRecordsReleasesAndClearsEvents(): void
    {
        $aggregate = new EventRecordingEntity(EntityId::fromInt(7));
        $aggregate->rename('new-name');

        self::assertTrue($aggregate->hasRecordedDomainEvents());
        $events = $aggregate->releaseDomainEvents();
        self::assertCount(1, $events);
        self::assertFalse($aggregate->hasRecordedDomainEvents());
        self::assertSame([], $aggregate->releaseDomainEvents());
        self::assertSame('fixture.renamed', $events[0]->eventName());
        self::assertTrue($events[0]->aggregateId()?->equals(EntityId::fromInt(7)) ?? false);
    }

    public function testDispatcherInvokesListenersInRegistrationOrder(): void
    {
        $dispatcher = new DomainEventDispatcher();
        $observed = [];

        $dispatcher->listen('fixture.renamed', static function (DomainEvent $event) use (&$observed): void {
            $observed[] = 'first:' . $event->eventName();
        });
        $dispatcher->listen('fixture.renamed', static function (DomainEvent $event) use (&$observed): void {
            $observed[] = 'second:' . $event->eventName();
        });

        $dispatcher->dispatch(new FixtureRenamed(EntityId::fromInt(9)));

        self::assertSame(['first:fixture.renamed', 'second:fixture.renamed'], $observed);
    }
}

final class EventRecordingEntity implements Entity
{
    use RecordsDomainEvents;

    public function __construct(private readonly EntityId $entityId)
    {
    }

    public function id(): EntityId
    {
        return $this->entityId;
    }

    public function rename(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Name cannot be empty.');
        }

        $this->recordDomainEvent(new FixtureRenamed($this->entityId));
    }
}

final readonly class FixtureRenamed extends AbstractDomainEvent
{
    public function eventName(): string
    {
        return 'fixture.renamed';
    }
}
