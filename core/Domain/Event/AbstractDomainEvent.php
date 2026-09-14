<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Event;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;

abstract readonly class AbstractDomainEvent implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        private ?EntityId $aggregateId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    final public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    final public function aggregateId(): ?EntityId
    {
        return $this->aggregateId;
    }
}
