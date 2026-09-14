<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Event;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface DomainEvent
{
    public function eventName(): string;

    public function occurredAt(): DateTimeImmutable;

    public function aggregateId(): ?EntityId;
}
