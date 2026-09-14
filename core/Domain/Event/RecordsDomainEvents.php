<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Event;

trait RecordsDomainEvents
{
    /** @var list<DomainEvent> */
    private array $recordedDomainEvents = [];

    final protected function recordDomainEvent(DomainEvent $event): void
    {
        $this->recordedDomainEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    final public function releaseDomainEvents(): array
    {
        $events = $this->recordedDomainEvents;
        $this->recordedDomainEvents = [];

        return $events;
    }

    final public function hasRecordedDomainEvents(): bool
    {
        return $this->recordedDomainEvents !== [];
    }
}
