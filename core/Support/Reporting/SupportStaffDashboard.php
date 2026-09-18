<?php
declare(strict_types=1);
namespace Forwext\Core\Support\Reporting;
use Forwext\Core\Support\Ticket\SupportTicket;
final readonly class SupportStaffDashboard {
    /**
     * @param list<SupportTicket> $queue
     * @param list<SupportCategoryMetric> $categories
     * @param list<SupportAuditEntry> $audit
     */
    public function __construct(
        public SupportDashboardSummary $summary,
        public array $queue,
        public array $categories,
        public array $audit,
    ) {}
}
