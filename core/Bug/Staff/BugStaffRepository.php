<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Domain\Entity\EntityId;

interface BugStaffRepository
{
    /** @return list<BugReport> */
    public function search(BugStaffFilter $filter): array;

    public function summary(): BugStaffSummary;

    /** @return list<BugCategoryMetric> */
    public function categoryMetrics(int $limit = 100): array;

    /** @return list<BugReport> */
    public function duplicateCandidates(BugReport $source, int $limit = 100): array;

    public function duplicateLink(EntityId $duplicateReportId): ?BugDuplicateLink;

    public function saveDuplicateLink(BugDuplicateLink $link): void;

    /** @return list<BugAuditEntry> */
    public function recentAudit(int $limit = 50): array;
}
