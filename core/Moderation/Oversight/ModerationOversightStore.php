<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\ModerationAuditEvent;

interface ModerationOversightStore
{
    public function append(ModerationAuditEvent $event): OversightEntry;

    public function findByAuditId(EntityId $auditId): ?OversightEntry;

    /** @return list<OversightEntry> */
    public function pageAfter(int $sequence, int $limit = 500): array;

    /** @return list<OversightEntry> */
    public function recent(int $limit = 100): array;

    public function chainState(): OversightChainState;
}
