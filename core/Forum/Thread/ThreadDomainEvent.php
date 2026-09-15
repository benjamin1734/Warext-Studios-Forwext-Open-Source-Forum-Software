<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Event\DomainEvent;

final readonly class ThreadDomainEvent implements DomainEvent
{
    public function __construct(
        private string $name,
        private EntityId $threadId,
        private DateTimeImmutable $at,
    ) {
        ThreadId::assert($this->threadId);
    }

    public function eventName(): string
    {
        return $this->name;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at->setTimezone(new DateTimeZone('UTC'));
    }

    public function aggregateId(): ?EntityId
    {
        return $this->threadId;
    }
}
