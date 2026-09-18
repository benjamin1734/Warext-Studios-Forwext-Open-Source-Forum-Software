<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Bug\Conversation\BugReportNotifier;
use Forwext\Core\Bug\Conversation\NullBugReportNotifier;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportOperationException;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class BugStaffService
{
    public const EXPORT_PERMISSION = 'bug.report.export';
    public const AUDIT_VIEW_PERMISSION = 'bug.audit.view';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportService $reports,
        private BugStaffRepository $staff,
        private PermissionGate $gate,
        private AuditRecorder $audit,
        private AuditRequestId $auditRequestId,
        private BugReportNotifier $notifier = new NullBugReportNotifier(),
        private BugDuplicateDetector $duplicates = new BugDuplicateDetector(),
        private BugCsvExporter $csv = new BugCsvExporter(),
    ) {
    }

    public function dashboard(BugStaffFilter $filter): BugStaffDashboard
    {
        $this->gate->require(PermissionKey::fromString(BugReportService::VIEW_ALL_PERMISSION));

        $canViewAudit = $this->gate->allows(PermissionKey::fromString(self::AUDIT_VIEW_PERMISSION));
        return new BugStaffDashboard(
            $filter,
            $this->staff->summary(),
            $this->staff->search($filter),
            $this->reports->categories(),
            $this->staff->categoryMetrics(),
            $canViewAudit ? $this->staff->recentAudit() : [],
            $this->gate->allows(PermissionKey::fromString(self::EXPORT_PERMISSION)),
            $canViewAudit,
        );
    }

    /** @return list<BugDuplicateSuggestion> */
    public function duplicateSuggestions(EntityId $reportId): array
    {
        $this->requireManage();
        $report = $this->reports->report($reportId);
        return $this->duplicates->suggest($report, $this->staff->duplicateCandidates($report));
    }

    public function duplicateLink(EntityId $reportId): ?BugDuplicateLink
    {
        $this->gate->require(PermissionKey::fromString(BugReportService::VIEW_ALL_PERMISSION));
        $this->reports->report($reportId);
        return $this->staff->duplicateLink($reportId);
    }

    public function assign(
        EntityId $reportId,
        ?EntityId $assigneeUserId,
        ?DateTimeImmutable $now = null,
    ): BugReport {
        $this->gate->require(PermissionKey::fromString(BugReportService::ASSIGN_PERMISSION));
        $before = $this->reports->report($reportId);
        $at = self::utc($now);

        return $this->atomic(function () use ($reportId, $assigneeUserId, $before, $at): BugReport {
            $after = $this->reports->assign($reportId, $assigneeUserId, $at);
            $this->appendAudit(
                'bug.report.assign',
                $reportId,
                ['assigned_user_id'=>$before->assignedUserId?->value()],
                ['assigned_user_id'=>$after->assignedUserId?->value()],
                $at,
            );
            return $after;
        });
    }

    public function changeSeverity(
        EntityId $reportId,
        BugReportSeverity $severity,
        ?DateTimeImmutable $now = null,
    ): BugReport {
        $this->requireManage();
        $before = $this->reports->report($reportId);
        $at = self::utc($now);

        return $this->atomic(function () use ($reportId, $severity, $before, $at): BugReport {
            $after = $this->reports->changeSeverity($reportId, $severity, $at);
            $this->appendAudit(
                'bug.report.severity',
                $reportId,
                ['severity'=>$before->severity->value],
                ['severity'=>$after->severity->value],
                $at,
            );
            return $after;
        });
    }

    public function changeCategory(
        EntityId $reportId,
        string $categoryKey,
        ?DateTimeImmutable $now = null,
    ): BugReport {
        $this->requireManage();
        $before = $this->reports->report($reportId);
        $at = self::utc($now);

        return $this->atomic(function () use ($reportId, $categoryKey, $before, $at): BugReport {
            $after = $this->reports->changeCategory($reportId, $categoryKey, $at);
            $this->appendAudit(
                'bug.report.category',
                $reportId,
                ['category'=>$before->categoryKey],
                ['category'=>$after->categoryKey],
                $at,
            );
            return $after;
        });
    }

    public function changeStatus(
        EntityId $reportId,
        BugReportStatus $status,
        ?DateTimeImmutable $now = null,
    ): BugReport {
        $this->requireManage();
        $before = $this->reports->report($reportId);
        $at = self::utc($now);

        $after = $this->atomic(function () use ($reportId, $status, $before, $at): BugReport {
            $after = $this->reports->changeStatus($reportId, $status, $at);
            if ($before->status !== $after->status) {
                $this->appendAudit(
                    'bug.report.status',
                    $reportId,
                    ['status'=>$before->status->value],
                    ['status'=>$after->status->value],
                    $at,
                );
            }
            return $after;
        });
        if ($before->status !== $after->status) {
            $this->safeNotify(fn () => $this->notifier->statusChanged($after));
        }
        return $after;
    }

    public function linkDuplicate(
        EntityId $duplicateReportId,
        EntityId $canonicalReportId,
        ?DateTimeImmutable $now = null,
    ): BugDuplicateLink {
        $this->requireManage();
        if ($duplicateReportId->equals($canonicalReportId)) {
            throw new InvalidArgumentException('A bug report cannot duplicate itself.');
        }

        $source = $this->reports->report($duplicateReportId);
        $canonical = $this->reports->report($canonicalReportId);
        if ($canonical->status === BugReportStatus::Duplicate) {
            throw new BugReportOperationException('A duplicate report cannot be used as the canonical target.');
        }

        $existing = $this->staff->duplicateLink($duplicateReportId);
        if ($existing !== null) {
            if ($existing->canonicalReportId->equals($canonicalReportId)) {
                return $existing;
            }
            throw new BugReportOperationException('Bug report is already linked to another canonical report.');
        }

        if ($source->status !== BugReportStatus::Duplicate
            && !$source->status->canTransitionTo(BugReportStatus::Duplicate)
        ) {
            throw new BugReportOperationException('Bug report must be reopened before it can be marked duplicate.');
        }

        $at = self::utc($now);
        $updated = $source;
        $link = new BugDuplicateLink(
            $duplicateReportId,
            $canonicalReportId,
            $this->gate->actorId(),
            $at,
        );

        $this->atomic(function () use ($source, $link, $at, &$updated): void {
            if ($source->status !== BugReportStatus::Duplicate) {
                $updated = $this->reports->changeStatus(
                    $source->reportId,
                    BugReportStatus::Duplicate,
                    $at,
                );
            }
            $this->staff->saveDuplicateLink($link);
            $this->appendAudit(
                'bug.report.duplicate.link',
                $source->reportId,
                ['status'=>$source->status->value,'canonical_report_id'=>null],
                ['status'=>BugReportStatus::Duplicate->value,'canonical_report_id'=>$link->canonicalReportId->value()],
                $at,
            );
        });

        if ($source->status !== $updated->status) {
            $this->safeNotify(fn () => $this->notifier->statusChanged($updated));
        }
        return $link;
    }

    public function export(BugStaffFilter $filter): string
    {
        $this->gate->require(PermissionKey::fromString(self::EXPORT_PERMISSION));
        $reports = $this->staff->search(new BugStaffFilter(
            $filter->text,
            $filter->status,
            $filter->severity,
            $filter->categoryKey,
            $filter->assignedUserId,
            $filter->unassignedOnly,
            min(1000, max(1, $filter->limit)),
            0,
        ));
        return $this->csv->export($reports, $this->staff);
    }

    private function requireManage(): void
    {
        $this->gate->require(PermissionKey::fromString(BugReportService::VIEW_ALL_PERMISSION));
        $this->gate->require(PermissionKey::fromString(BugReportService::MANAGE_PERMISSION));
    }

    /**
     * @param array<string,scalar|null> $before
     * @param array<string,scalar|null> $after
     */
    private function appendAudit(
        string $action,
        EntityId $reportId,
        array $before,
        array $after,
        DateTimeImmutable $at,
    ): void {
        $this->audit->append(new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Bug,
            $this->gate->actorId(),
            AuditAction::fromString($action),
            'bug.report',
            $reportId->value(),
            null,
            null,
            $this->auditRequestId,
            $before,
            $after,
            $at,
        ));
    }

    private function atomic(Closure $callback): mixed
    {
        return $this->database->inTransaction()
            ? $callback()
            : $this->database->transaction(static fn () => $callback());
    }

    private function safeNotify(Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Durable bug state is authoritative; notification delivery remains best-effort.
        }
    }

    private static function utc(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
