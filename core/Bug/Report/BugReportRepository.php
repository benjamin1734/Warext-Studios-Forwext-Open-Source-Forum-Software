<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Report;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface BugReportRepository
{
    /** @return list<BugReportCategory> */
    public function activeCategories(): array;

    public function category(string $key): ?BugReportCategory;
    public function saveCategory(BugReportCategory $category): void;

    public function create(BugReport $report): void;
    public function find(EntityId $reportId): ?BugReport;

    /** @return list<BugReport> */
    public function forReporter(EntityId $reporterUserId, int $limit = 50): array;

    public function assign(
        EntityId $reportId,
        ?EntityId $assignedUserId,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport;

    public function changeStatus(
        EntityId $reportId,
        BugReportStatus $status,
        ?DateTimeImmutable $finalizedAt,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport;

    public function changeSeverity(
        EntityId $reportId,
        BugReportSeverity $severity,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport;

    public function changeCategory(
        EntityId $reportId,
        string $categoryKey,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport;

    public function appendHistory(BugReportHistoryEntry $entry): void;

    /** @return list<BugReportHistoryEntry> */
    public function history(EntityId $reportId, bool $includeStaff, int $limit = 200): array;
}
