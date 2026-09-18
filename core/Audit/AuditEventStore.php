<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

use Forwext\Core\Domain\Entity\EntityId;

interface AuditEventStore
{
    public function append(AuditEvent $event): void;

    /** @return list<AuditEvent> */
    public function recent(int $limit = 100): array;

    /** @return list<AuditEvent> */
    public function recentForTarget(
        string $targetType,
        string $targetId,
        int $limit = 100,
        ?AuditScope $scope = null,
    ): array;

    /** @return list<AuditEvent> */
    public function recentForActor(EntityId $actorUserId, int $limit = 100): array;

    /** @return list<AuditEvent> */
    public function recentForRequest(AuditRequestId $requestId, int $limit = 100): array;
}
