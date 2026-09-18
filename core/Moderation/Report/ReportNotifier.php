<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use Forwext\Core\Domain\Entity\EntityId;

interface ReportNotifier
{
    public function received(EntityId $reporterUserId, ReportReceipt $receipt, ReportableContent $content): void;

    public function assigned(EntityId $moderatorUserId, ReportGroup $group): void;

    /** @param list<EntityId> $reporterUserIds */
    public function statusChanged(array $reporterUserIds, ReportGroup $group): void;
}
