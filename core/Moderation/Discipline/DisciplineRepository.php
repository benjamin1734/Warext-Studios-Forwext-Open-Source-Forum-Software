<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface DisciplineRepository
{
    /** @return list<WarningDefinition> */
    public function warningDefinitions(bool $includeInactive = false): array;
    public function warningDefinition(string $key): ?WarningDefinition;
    public function saveWarningDefinition(WarningDefinition $definition, DateTimeImmutable $at): void;
    public function insertAction(DisciplineAction $action): void;
    public function action(EntityId $actionId): ?DisciplineAction;
    public function revoke(EntityId $actionId, EntityId $actorUserId, string $reason, DateTimeImmutable $at): DisciplineAction;
    /** @return list<DisciplineAction> */
    public function forUser(EntityId $userId, int $limit = 100): array;
    /** @param list<DisciplineActionType> $types @return list<DisciplineAction> */
    public function recent(array $types, int $limit = 50): array;
    /** @param list<DisciplineActionType> $types */
    public function activeCount(array $types, DateTimeImmutable $at): int;
    public function activePoints(EntityId $userId, DateTimeImmutable $at): int;
}
