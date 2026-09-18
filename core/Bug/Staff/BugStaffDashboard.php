<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportCategory;

final readonly class BugStaffDashboard
{
    /**
     * @param list<BugReport> $reports
     * @param list<BugReportCategory> $categories
     * @param list<BugCategoryMetric> $categoryMetrics
     * @param list<BugAuditEntry> $audit
     */
    public function __construct(
        public BugStaffFilter $filter,
        public BugStaffSummary $summary,
        public array $reports,
        public array $categories,
        public array $categoryMetrics,
        public array $audit,
        public bool $canExport,
        public bool $canViewAudit,
    ) {
    }
}
