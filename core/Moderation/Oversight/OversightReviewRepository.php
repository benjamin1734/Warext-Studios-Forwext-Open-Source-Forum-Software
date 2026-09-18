<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface OversightReviewRepository
{
    public function insertCase(OversightReviewCase $case): void;
    public function reviewCase(EntityId $caseId): ?OversightReviewCase;
    /** @return list<OversightReviewCase> */
    public function openCases(int $limit = 100): array;
    public function resolveCase(EntityId $caseId, EntityId $actorUserId, string $resolution, DateTimeImmutable $at): void;

    public function insertFlag(OversightAnomalyFlag $flag): void;
    public function flag(EntityId $flagId): ?OversightAnomalyFlag;
    /** @return list<OversightAnomalyFlag> */
    public function openFlags(int $limit = 100): array;
    public function resolveFlag(EntityId $flagId, EntityId $actorUserId, string $resolution, DateTimeImmutable $at): void;
}
