<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Freshness;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ThreadFreshnessRepository
{
    public function policy(EntityId $forumNodeId): ?ThreadFreshnessPolicy;

    public function savePolicy(ThreadFreshnessPolicy $policy, DateTimeImmutable $at): void;

    public function snapshot(EntityId $threadId, DateTimeImmutable $now): ?ThreadFreshnessSnapshot;

    /** @return list<EntityId> */
    public function maintenanceThreadIds(DateTimeImmutable $now, int $limit = 100): array;

    public function markEvaluated(EntityId $threadId, DateTimeImmutable $at): void;

    public function markNotified(EntityId $threadId, DateTimeImmutable $at): bool;

    public function autoLock(EntityId $threadId, DateTimeImmutable $at): bool;

    public function autoArchive(EntityId $threadId, DateTimeImmutable $at): bool;

    public function autoUnfeature(EntityId $threadId, DateTimeImmutable $at): bool;

    public function requestReview(EntityId $threadId, DateTimeImmutable $at): bool;

    /** @return list<ThreadFreshnessReview> */
    public function pendingReviews(DateTimeImmutable $now, int $limit = 100): array;

    public function resolveReview(
        EntityId $threadId,
        EntityId $actorUserId,
        string $resolution,
        DateTimeImmutable $at,
    ): bool;

    public function renew(
        EntityId $threadId,
        EntityId $actorUserId,
        DateTimeImmutable $at,
        bool $reopenArchived,
    ): void;

    /** @return list<EntityId> */
    public function postIds(EntityId $threadId, int $limit = 10000): array;
}
