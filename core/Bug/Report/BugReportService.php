<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Report;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class BugReportService
{
    public const CREATE_PERMISSION = 'bug.report.create';
    public const VIEW_OWN_PERMISSION = 'bug.report.view_own';
    public const VIEW_ALL_PERMISSION = 'bug.report.view_all';
    public const MANAGE_PERMISSION = 'bug.report.manage';
    public const ASSIGN_PERMISSION = 'bug.report.assign';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportRepository $reports,
        private PermissionGate $gate,
        private PermissionAuthorizer $authorizer,
        private BugReportNotifier $notifier = new NullBugReportNotifier(),
    ) {
    }

    /** @return list<BugReportCategory> */
    public function categories(): array
    {
        if (!$this->gate->allows(PermissionKey::fromString(self::CREATE_PERMISSION))
            && !$this->gate->allows(PermissionKey::fromString(self::VIEW_ALL_PERMISSION))
        ) {
            $this->gate->require(PermissionKey::fromString(self::CREATE_PERMISSION));
        }
        return $this->reports->activeCategories();
    }

    public function saveCategory(BugReportCategory $category): void
    {
        $this->requireStaff(self::MANAGE_PERMISSION);
        $this->reports->saveCategory($category);
    }

    public function create(
        string $categoryKey,
        string $title,
        string $summary,
        ?BugReportSeverity $severity = null,
        ?DateTimeImmutable $now = null,
    ): BugReport {
        $this->gate->require(PermissionKey::fromString(self::CREATE_PERMISSION));

        $categoryKey = strtolower(trim($categoryKey));
        $category = $this->reports->category($categoryKey);
        if ($category === null || !$category->active) {
            throw new InvalidArgumentException('Bug report category is unavailable.');
        }

        $title = trim($title);
        $summary = trim($summary);
        $at = self::utc($now);
        $report = new BugReport(
            BugReport::generateId(),
            $category->key,
            $this->gate->actorId(),
            null,
            $title,
            $summary,
            $severity ?? $category->defaultSeverity,
            BugReportStatus::New,
            null,
            $at,
            $at,
            1,
        );

        $this->atomic(function () use ($report, $at): void {
            $this->reports->create($report);
            $this->appendHistory(
                $report->reportId,
                BugHistoryEventType::Created,
                BugHistoryVisibility::Public,
                [
                    'status'=>$report->status->value,
                    'category'=>$report->categoryKey,
                    'severity'=>$report->severity->value,
                ],
                $at,
            );
        });

        return $report;
    }

    /** @return list<BugReport> */
    public function own(int $limit = 50): array
    {
        $this->gate->require(PermissionKey::fromString(self::VIEW_OWN_PERMISSION));
        return $this->reports->forReporter($this->gate->actorId(), $limit);
    }

    public function report(EntityId $reportId): BugReport
    {
        $report = $this->required($reportId);
        if ($report->isReporter($this->gate->actorId())) {
            $this->gate->require(PermissionKey::fromString(self::VIEW_OWN_PERMISSION));
            return $report;
        }

        $this->gate->require(PermissionKey::fromString(self::VIEW_ALL_PERMISSION));
        return $report;
    }

    /** @return list<BugReportHistoryEntry> */
    public function history(EntityId $reportId, int $limit = 200): array
    {
        $this->report($reportId);
        $staff = $this->gate->allows(PermissionKey::fromString(self::VIEW_ALL_PERMISSION));
        return $this->reports->history($reportId, $staff, $limit);
    }

    public function assign(
        EntityId $reportId,
        ?EntityId $assignedUserId,
        ?DateTimeImmutable $now = null,
    ): BugReport {
        $this->requireStaff(self::ASSIGN_PERMISSION);
        if ($assignedUserId !== null
            && !$this->authorizer->allows(
                $assignedUserId,
                PermissionKey::fromString(self::VIEW_ALL_PERMISSION),
            )
        ) {
            throw new InvalidArgumentException('Bug report assignee does not have bug-report staff access.');
        }

        return $this->atomic(function () use ($reportId, $assignedUserId, $now): BugReport {
            $current = $this->required($reportId);
            if ($current->status->isTerminal()) {
                throw new BugReportOperationException('Terminal bug reports must be reopened before assignment.');
            }
            if ($current->assignedUserId?->value() === $assignedUserId?->value()) {
                return $current;
            }

            $at = self::utc($now);
            $updated = $this->reports->assign(
                $reportId,
                $assignedUserId,
                $current->version,
                $at,
            );
            $this->appendHistory(
                $reportId,
                BugHistoryEventType::Assigned,
                BugHistoryVisibility::Staff,
                [
                    'from_user_id'=>$current->assignedUserId?->value(),
                    'to_user_id'=>$updated->assignedUserId?->value(),
                ],
                $at,
            );
            return $updated;
        });
    }

    public function changeStatus(
        EntityId $reportId,
        BugReportStatus $status,
        ?DateTimeImmutable $now = null,
    ): BugReport {
        $this->requireStaff(self::MANAGE_PERMISSION);

        return $this->atomic(function () use ($reportId, $status, $now): BugReport {
            $current = $this->required($reportId);
            if ($current->status === $status) {
                return $current;
            }
            if (!$current->status->canTransitionTo($status)) {
                throw new BugReportOperationException('Requested bug-report lifecycle transition is not allowed.');
            }

            $at = self::utc($now);
            $updated = $this->reports->changeStatus(
                $reportId,
                $status,
                $status->isTerminal() ? $at : null,
                $current->version,
                $at,
            );
            $this->appendHistory(
                $reportId,
                BugHistoryEventType::StatusChanged,
                BugHistoryVisibility::Public,
                ['from'=>$current->status->value,'to'=>$updated->status->value],
                $at,
            );
            $this->notifier->statusChanged($updated);
            return $updated;
        });
    }

    public function changeSeverity(
        EntityId $reportId,
        BugReportSeverity $severity,
        ?DateTimeImmutable $now = null,
    ): BugReport {
        $this->requireStaff(self::MANAGE_PERMISSION);

        return $this->atomic(function () use ($reportId, $severity, $now): BugReport {
            $current = $this->required($reportId);
            if ($current->severity === $severity) {
                return $current;
            }

            $at = self::utc($now);
            $updated = $this->reports->changeSeverity(
                $reportId,
                $severity,
                $current->version,
                $at,
            );
            $this->appendHistory(
                $reportId,
                BugHistoryEventType::SeverityChanged,
                BugHistoryVisibility::Public,
                ['from'=>$current->severity->value,'to'=>$updated->severity->value],
                $at,
            );
            return $updated;
        });
    }

    public function addReporterInfo(
        EntityId $reportId,
        string $body,
        ?DateTimeImmutable $now = null,
    ): BugReportHistoryEntry {
        $report = $this->report($reportId);
        if (!$report->isReporter($this->gate->actorId())) {
            throw new BugReportOperationException('Only the bug reporter can add reporter information.');
        }
        if ($report->status->isTerminal()) {
            throw new BugReportOperationException('Terminal bug reports must be reopened before adding information.');
        }

        $body = trim($body);
        if ($body === '' || strlen($body) > 10000) {
            throw new InvalidArgumentException('Bug report additional information must contain 1-10000 UTF-8 bytes.');
        }
        $at = self::utc($now);

        return $this->atomic(function () use ($report, $body, $at): BugReportHistoryEntry {
            $entry = $this->appendHistory(
                $report->reportId,
                BugHistoryEventType::ReporterInfoAdded,
                BugHistoryVisibility::Public,
                ['body'=>$body],
                $at,
            );
            $this->notifier->reporterInfoAdded($report, $entry);
            return $entry;
        });
    }

    public function staffRespond(
        EntityId $reportId,
        string $body,
        ?DateTimeImmutable $now = null,
    ): BugReportHistoryEntry {
        $this->requireStaff(self::MANAGE_PERMISSION);
        $report = $this->required($reportId);

        $body = trim($body);
        if ($body === '' || strlen($body) > 10000) {
            throw new InvalidArgumentException('Bug report staff response must contain 1-10000 UTF-8 bytes.');
        }
        $at = self::utc($now);

        return $this->atomic(function () use ($report, $body, $at): BugReportHistoryEntry {
            $entry = $this->appendHistory(
                $report->reportId,
                BugHistoryEventType::StaffResponse,
                BugHistoryVisibility::Public,
                ['body'=>$body],
                $at,
            );
            $this->notifier->staffResponse($report, $entry);
            return $entry;
        });
    }

    public function changeCategory(
        EntityId $reportId,
        string $categoryKey,
        ?DateTimeImmutable $now = null,
    ): BugReport {
        $this->requireStaff(self::MANAGE_PERMISSION);
        $categoryKey = strtolower(trim($categoryKey));
        $category = $this->reports->category($categoryKey);
        if ($category === null || !$category->active) {
            throw new InvalidArgumentException('Bug report category is unavailable.');
        }

        return $this->atomic(function () use ($reportId, $category, $now): BugReport {
            $current = $this->required($reportId);
            if ($current->categoryKey === $category->key) {
                return $current;
            }

            $at = self::utc($now);
            $updated = $this->reports->changeCategory(
                $reportId,
                $category->key,
                $current->version,
                $at,
            );
            $this->appendHistory(
                $reportId,
                BugHistoryEventType::CategoryChanged,
                BugHistoryVisibility::Public,
                ['from'=>$current->categoryKey,'to'=>$updated->categoryKey],
                $at,
            );
            return $updated;
        });
    }

    private function requireStaff(string $permission): void
    {
        $this->gate->require(PermissionKey::fromString(self::VIEW_ALL_PERMISSION));
        $this->gate->require(PermissionKey::fromString($permission));
    }

    private function required(EntityId $reportId): BugReport
    {
        return $this->reports->find($reportId)
            ?? throw new BugReportNotFoundException('Bug report was not found.');
    }

    /**
     * @param array<string,scalar|null> $payload
     */
    private function appendHistory(
        EntityId $reportId,
        BugHistoryEventType $eventType,
        BugHistoryVisibility $visibility,
        array $payload,
        DateTimeImmutable $at,
    ): BugReportHistoryEntry {
        $entry = new BugReportHistoryEntry(
            BugReportHistoryEntry::generateId(),
            $reportId,
            $this->gate->actorId(),
            $eventType,
            $visibility,
            $payload,
            $at,
        );
        $this->reports->appendHistory($entry);
        return $entry;
    }

    private function atomic(Closure $callback): mixed
    {
        return $this->database->inTransaction()
            ? $callback()
            : $this->database->transaction(static fn () => $callback());
    }

    private static function utc(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
