<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class ReportReceipt
{
    public function __construct(
        public EntityId $reportId,
        public EntityId $groupId,
        public bool $created,
        public bool $groupWasExisting,
    ) {
    }
}
