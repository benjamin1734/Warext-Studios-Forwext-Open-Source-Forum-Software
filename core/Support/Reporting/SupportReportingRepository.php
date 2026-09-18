<?php
declare(strict_types=1);
namespace Forwext\Core\Support\Reporting;
use DateTimeImmutable;
interface SupportReportingRepository {
    public function summary(DateTimeImmutable $now):SupportDashboardSummary;
    /** @return list<SupportCategoryMetric> */
    public function categoryMetrics(DateTimeImmutable $now,int $limit=100):array;
    /** @return list<SupportAuditEntry> */
    public function recentAudit(int $limit=50):array;
}
