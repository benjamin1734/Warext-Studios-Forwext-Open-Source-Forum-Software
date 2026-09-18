<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class CoreAuditService
{
    public function __construct(
        private AuditEventStore $store,
        private PermissionGate $gate,
    ) {
    }

    /** @return list<AuditEvent> */
    public function recent(int $limit = 100): array
    {
        $this->gate->require(PermissionKey::fromString('audit.view'));
        return $this->store->recent($limit);
    }

    /** @return list<AuditEvent> */
    public function forActor(EntityId $actorUserId, int $limit = 100): array
    {
        $this->gate->require(PermissionKey::fromString('audit.view'));
        return $this->store->recentForActor($actorUserId, $limit);
    }

    /** @return list<AuditEvent> */
    public function forRequest(AuditRequestId $requestId, int $limit = 100): array
    {
        $this->gate->require(PermissionKey::fromString('audit.view'));
        return $this->store->recentForRequest($requestId, $limit);
    }
}
