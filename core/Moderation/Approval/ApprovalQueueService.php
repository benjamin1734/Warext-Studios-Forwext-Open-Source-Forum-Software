<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Approval;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;

final readonly class ApprovalQueueService
{
    public function __construct(
        private ApprovalQueueRegistry $registry,
        private PermissionGate $gate,
    ) {
    }

    public function snapshot(int $limit = 50): ApprovalQueueSnapshot
    {
        $this->gate->require(PermissionKey::fromString('moderation.access'));
        return new ApprovalQueueSnapshot($this->registry->count(), $this->registry->latest($limit));
    }

    /** @param list<ApprovalQueueSelection> $selections */
    public function moderate(
        ApprovalQueueAction $action,
        array $selections,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): int {
        $this->gate->require(PermissionKey::fromString('moderation.manage'));
        return $this->registry->moderate($action, $selections, $reason, $requestId, $at);
    }
}
