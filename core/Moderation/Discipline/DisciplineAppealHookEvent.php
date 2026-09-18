<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Event\AbstractDomainEvent;

final readonly class DisciplineAppealHookEvent extends AbstractDomainEvent
{
    public function __construct(
        public EntityId $actionId,
        public EntityId $userId,
        public DisciplineActionType $actionType,
        public string $appealReference,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($actionId, $occurredAt);
    }

    public function eventName(): string
    {
        return 'moderation.discipline.appeal_available';
    }
}
