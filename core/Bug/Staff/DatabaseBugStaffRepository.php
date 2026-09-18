<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;
use ValueError;

final readonly class DatabaseBugStaffRepository implements BugStaffRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function search(BugStaffFilter $filter): array
    {
        $where = [];
        $parameters = [];

        if ($filter->text !== null) {
            $needle = $this->like(trim($filter->text));
            $where[] = '(LOWER(r.title) LIKE :text_title ESCAPE \'!\' OR LOWER(r.summary) LIKE :text_summary ESCAPE \'!\')';
            $parameters['text_title'] = '%' . $needle . '%';
            $parameters['text_summary'] = '%' . $needle . '%';
        }
        if ($filter->status !== null) {
            $where[] = 'r.status=:status';
            $parameters['status'] = $filter->status->value;
        }
        if ($filter->severity !== null) {
            $where[] = 'r.severity=:severity';
            $parameters['severity'] = $filter->severity->value;
        }
        if ($filter->categoryKey !== null) {
            $where[] = 'r.category_key=:category_key';
            $parameters['category_key'] = $filter->categoryKey;
        }
        if ($filter->assignedUserId !== null) {
            $where[] = 'r.assigned_user_id=:assigned_user_id';
            $parameters['assigned_user_id'] = $filter->assignedUserId->value();
        } elseif ($filter->unassignedOnly) {
            $where[] = 'r.assigned_user_id IS NULL';
        }

        $sql = $this->reportSelect() . ' r';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY "
            . "CASE r.status WHEN 'new' THEN 0 WHEN 'in_review' THEN 1 ELSE 2 END,"
            . "CASE r.severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END,"
            . 'r.updated_at_utc DESC,r.report_id DESC '
            . 'LIMIT ' . $filter->limit . ' OFFSET ' . $filter->offset;

        $rows = $this->database->fetchAll(new CompiledQuery($sql, $parameters));
        return array_map($this->hydrateReport(...), $rows);
    }

    public function summary(): BugStaffSummary
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            "SELECT COUNT(*) AS total,"
            . "COALESCE(SUM(status='new'),0) AS new_count,"
            . "COALESCE(SUM(status='in_review'),0) AS in_review_count,"
            . "COALESCE(SUM(status='resolved'),0) AS resolved_count,"
            . "COALESCE(SUM(status='rejected'),0) AS rejected_count,"
            . "COALESCE(SUM(status='duplicate'),0) AS duplicate_count,"
            . "COALESCE(SUM(assigned_user_id IS NULL AND status IN ('new','in_review')),0) AS unassigned_count "
            . 'FROM forwext_bug_reports',
        )) ?? [];

        return new BugStaffSummary(
            (int) ($row['total'] ?? 0),
            (int) ($row['new_count'] ?? 0),
            (int) ($row['in_review_count'] ?? 0),
            (int) ($row['resolved_count'] ?? 0),
            (int) ($row['rejected_count'] ?? 0),
            (int) ($row['duplicate_count'] ?? 0),
            (int) ($row['unassigned_count'] ?? 0),
        );
    }

    public function categoryMetrics(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new RuntimeException('Bug category metric limit is invalid.');
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            "SELECT c.category_key,c.label,COUNT(r.report_id) AS total,"
            . "COALESCE(SUM(r.status IN ('new','in_review')),0) AS active_count,"
            . "COALESCE(SUM(r.status IN ('resolved','rejected','duplicate')),0) AS terminal_count,"
            . "COALESCE(SUM(r.status='duplicate'),0) AS duplicate_count "
            . 'FROM forwext_bug_report_categories c '
            . 'LEFT JOIN forwext_bug_reports r ON r.category_key=c.category_key '
            . 'GROUP BY c.category_key,c.label,c.sort_order '
            . 'ORDER BY c.sort_order,c.category_key LIMIT ' . $limit,
        ));

        return array_map(
            static fn (array $row): BugCategoryMetric => new BugCategoryMetric(
                (string) $row['category_key'],
                (string) $row['label'],
                (int) $row['total'],
                (int) $row['active_count'],
                (int) $row['terminal_count'],
                (int) $row['duplicate_count'],
            ),
            $rows,
        );
    }

    public function duplicateCandidates(BugReport $source, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new RuntimeException('Bug duplicate candidate limit is invalid.');
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->reportSelect() . ' r '
            . "WHERE r.report_id<>:report_id AND r.status<>'duplicate' "
            . "ORDER BY (r.category_key=:category_key) DESC,r.updated_at_utc DESC,r.report_id DESC LIMIT " . $limit,
            ['report_id'=>$source->reportId->value(),'category_key'=>$source->categoryKey],
        ));
        return array_map($this->hydrateReport(...), $rows);
    }

    public function duplicateLink(EntityId $duplicateReportId): ?BugDuplicateLink
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT duplicate_report_id,canonical_report_id,created_by_user_id,created_at_utc '
            . 'FROM forwext_bug_report_duplicates WHERE duplicate_report_id=:duplicate_report_id LIMIT 1',
            ['duplicate_report_id'=>$duplicateReportId->value()],
        ));
        return $row === null ? null : $this->hydrateDuplicateLink($row);
    }

    public function saveDuplicateLink(BugDuplicateLink $link): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_bug_report_duplicates '
            . '(duplicate_report_id,canonical_report_id,created_by_user_id,created_at_utc) '
            . 'VALUES (:duplicate_report_id,:canonical_report_id,:created_by_user_id,:created_at_utc)',
            [
                'duplicate_report_id'=>$link->duplicateReportId->value(),
                'canonical_report_id'=>$link->canonicalReportId->value(),
                'created_by_user_id'=>$link->createdByUserId->value(),
                'created_at_utc'=>$this->format($link->createdAt),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Bug duplicate relation was not persisted.');
        }
    }

    public function deleteDuplicateLink(EntityId $duplicateReportId): bool
    {
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_bug_report_duplicates WHERE duplicate_report_id=:duplicate_report_id',
            ['duplicate_report_id'=>$duplicateReportId->value()],
            true,
        )) === 1;
    }

    public function recentAudit(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new RuntimeException('Bug audit list limit is invalid.');
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            "SELECT audit_id,actor_user_id,action,target_type,target_id,request_id,occurred_at_utc "
            . "FROM forwext_core_audit_events WHERE scope='bug' "
            . 'ORDER BY occurred_at_utc DESC,audit_id DESC LIMIT ' . $limit,
        ));

        return array_map(
            fn (array $row): BugAuditEntry => new BugAuditEntry(
                EntityId::fromString((string) $row['audit_id']),
                UserId::fromStored((string) $row['actor_user_id']),
                (string) $row['action'],
                (string) $row['target_type'],
                (string) $row['target_id'],
                (string) $row['request_id'],
                $this->parse((string) $row['occurred_at_utc']),
            ),
            $rows,
        );
    }

    private function reportSelect(): string
    {
        return 'SELECT r.report_id,r.category_key,r.reporter_user_id,r.assigned_user_id,r.title,r.summary,'
            . 'r.severity,r.status,r.finalized_at_utc,r.created_at_utc,r.updated_at_utc,r.version '
            . 'FROM forwext_bug_reports';
    }

    /** @param array<string,mixed> $row */
    private function hydrateReport(array $row): BugReport
    {
        try {
            $severity = BugReportSeverity::from((string) $row['severity']);
            $status = BugReportStatus::from((string) $row['status']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored bug report staff row is invalid.', previous: $exception);
        }

        return new BugReport(
            EntityId::fromString((string) $row['report_id']),
            (string) $row['category_key'],
            $row['reporter_user_id'] === null ? null : UserId::fromStored((string) $row['reporter_user_id']),
            $row['assigned_user_id'] === null ? null : UserId::fromStored((string) $row['assigned_user_id']),
            (string) $row['title'],
            (string) $row['summary'],
            $severity,
            $status,
            $this->parseNullable($row['finalized_at_utc'] ?? null),
            $this->parse((string) $row['created_at_utc']),
            $this->parse((string) $row['updated_at_utc']),
            (int) $row['version'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateDuplicateLink(array $row): BugDuplicateLink
    {
        return new BugDuplicateLink(
            EntityId::fromString((string) $row['duplicate_report_id']),
            EntityId::fromString((string) $row['canonical_report_id']),
            $row['created_by_user_id'] === null ? null : UserId::fromStored((string) $row['created_by_user_id']),
            $this->parse((string) $row['created_at_utc']),
        );
    }

    private function like(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return str_replace(['!','%','_'], ['!!','!%','!_'], $value);
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored bug staff timestamp is invalid.');
        }
        return $time;
    }

    private function parseNullable(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? $this->parse($value) : null;
    }
}
