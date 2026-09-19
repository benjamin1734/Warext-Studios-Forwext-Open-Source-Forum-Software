<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

use Forwext\Core\Domain\Entity\EntityId;

interface TrophyRepository
{
    public function findDefinition(EntityId $trophyId): ?TrophyDefinition;

    /** @return list<TrophyDefinition> */
    public function definitions(bool $activeOnly = false, int $limit = 500): array;

    public function saveDefinition(TrophyDefinition $definition): void;

    public function grantForUser(EntityId $trophyId, EntityId $userId): ?TrophyGrant;

    /** @return list<array{definition:TrophyDefinition,grant:TrophyGrant}> */
    public function activeForUser(EntityId $userId, int $limit = 100): array;

    /** @return list<TrophyHistoryEntry> */
    public function historyForUser(EntityId $userId, int $limit = 100, int $offset = 0): array;

    public function saveGrant(TrophyGrant $grant, TrophyHistoryEntry $history): void;

    public function evaluationCursor(): ?EntityId;

    public function setEvaluationCursor(?EntityId $userId): void;

    /** @return list<EntityId> */
    public function evaluationCandidates(?EntityId $afterUserId, int $limit): array;
}
