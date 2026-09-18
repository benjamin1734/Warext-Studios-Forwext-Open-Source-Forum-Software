<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use JsonException;
use RuntimeException;
use ValueError;

final readonly class DatabaseBugReportRepository implements BugReportRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function activeCategories(): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT category_key,label,description,default_severity,sort_order,active '
            . 'FROM forwext_bug_report_categories WHERE active=1 ORDER BY sort_order,category_key',
        ));
        return array_map($this->hydrateCategory(...), $rows);
    }

    public function category(string $key): ?BugReportCategory
    {
        BugReportCategory::assertKey($key);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT category_key,label,description,default_severity,sort_order,active '
            . 'FROM forwext_bug_report_categories WHERE category_key=:category_key LIMIT 1',
            ['category_key'=>$key],
        ));
        return $row === null ? null : $this->hydrateCategory($row);
    }

    public function saveCategory(BugReportCategory $category): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_bug_report_categories '
            . '(category_key,label,description,default_severity,sort_order,active,created_at_utc,updated_at_utc) '
            . 'VALUES (:category_key,:label,:description,:default_severity,:sort_order,:active,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),'
            . 'default_severity=VALUES(default_severity),sort_order=VALUES(sort_order),'
            . 'active=VALUES(active),updated_at_utc=VALUES(updated_at_utc)',
            [
                'category_key'=>$category->key,
                'label'=>$category->label,
                'description'=>$category->description,
                'default_severity'=>$category->defaultSeverity->value,
                'sort_order'=>$category->sortOrder,
                'active'=>$category->active,
            ],
        ));
        if ($affected > 2) {
            throw new BugReportOperationException('Bug report category mutation affected an invalid row count.');
        }
    }

    public function create(BugReport $report): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_bug_reports '
            . '(report_id,category_key,reporter_user_id,assigned_user_id,title,summary,severity,status,'
            . 'finalized_at_utc,created_at_utc,updated_at_utc,version) '
            . 'VALUES (:report_id,:category_key,:reporter_user_id,:assigned_user_id,:title,:summary,:severity,:status,'
            . ':finalized_at,:created_at,:updated_at,:version)',
            [
                'report_id'=>$report->reportId->value(),
                'category_key'=>$report->categoryKey,
                'reporter_user_id'=>$report->reporterUserId?->value(),
                'assigned_user_id'=>$report->assignedUserId?->value(),
                'title'=>$report->title,
                'summary'=>$report->summary,
                'severity'=>$report->severity->value,
                'status'=>$report->status->value,
                'finalized_at'=>self::formatNullable($report->finalizedAt),
                'created_at'=>self::format($report->createdAt),
                'updated_at'=>self::format($report->updatedAt),
                'version'=>$report->version,
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new BugReportOperationException('Bug report was not persisted.');
        }
    }

    public function find(EntityId $reportId): ?BugReport
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->reportSelect() . ' WHERE report_id=:report_id LIMIT 1',
            ['report_id'=>$reportId->value()],
        ));
        return $row === null ? null : $this->hydrateReport($row);
    }

    public function forReporter(EntityId $reporterUserId, int $limit = 50): array
    {
        UserId::assert($reporterUserId);
        self::assertLimit($limit, 100);
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->reportSelect() . ' WHERE reporter_user_id=:reporter_user_id '
            . 'ORDER BY updated_at_utc DESC,report_id DESC LIMIT ' . $limit,
            ['reporter_user_id'=>$reporterUserId->value()],
        ));
        return array_map($this->hydrateReport(...), $rows);
    }

    public function assign(
        EntityId $reportId,
        ?EntityId $assignedUserId,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport {
        if ($assignedUserId !== null) {
            UserId::assert($assignedUserId);
        }
        $this->optimisticUpdate(
            'UPDATE forwext_bug_reports SET assigned_user_id=:assigned_user_id,updated_at_utc=:updated_at,'
            . 'version=version+1 WHERE report_id=:report_id AND version=:expected_version',
            [
                'assigned_user_id'=>$assignedUserId?->value(),
                'updated_at'=>self::format($now),
                'report_id'=>$reportId->value(),
                'expected_version'=>$expectedVersion,
            ],
            $reportId,
        );
        return $this->required($reportId);
    }

    public function changeStatus(
        EntityId $reportId,
        BugReportStatus $status,
        ?DateTimeImmutable $finalizedAt,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport {
        $this->optimisticUpdate(
            'UPDATE forwext_bug_reports SET status=:status,finalized_at_utc=:finalized_at,updated_at_utc=:updated_at,'
            . 'version=version+1 WHERE report_id=:report_id AND version=:expected_version',
            [
                'status'=>$status->value,
                'finalized_at'=>self::formatNullable($finalizedAt),
                'updated_at'=>self::format($now),
                'report_id'=>$reportId->value(),
                'expected_version'=>$expectedVersion,
            ],
            $reportId,
        );
        return $this->required($reportId);
    }

    public function changeSeverity(
        EntityId $reportId,
        BugReportSeverity $severity,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport {
        $this->optimisticUpdate(
            'UPDATE forwext_bug_reports SET severity=:severity,updated_at_utc=:updated_at,'
            . 'version=version+1 WHERE report_id=:report_id AND version=:expected_version',
            [
                'severity'=>$severity->value,
                'updated_at'=>self::format($now),
                'report_id'=>$reportId->value(),
                'expected_version'=>$expectedVersion,
            ],
            $reportId,
        );
        return $this->required($reportId);
    }

    public function changeCategory(
        EntityId $reportId,
        string $categoryKey,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport {
        BugReportCategory::assertKey($categoryKey);
        $this->optimisticUpdate(
            'UPDATE forwext_bug_reports SET category_key=:category_key,updated_at_utc=:updated_at,'
            . 'version=version+1 WHERE report_id=:report_id AND version=:expected_version',
            [
                'category_key'=>$categoryKey,
                'updated_at'=>self::format($now),
                'report_id'=>$reportId->value(),
                'expected_version'=>$expectedVersion,
            ],
            $reportId,
        );
        return $this->required($reportId);
    }

    public function appendHistory(BugReportHistoryEntry $entry): void
    {
        try {
            $payload = json_encode(
                $entry->payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new BugReportOperationException('Bug report history payload cannot be encoded.', previous: $exception);
        }

        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_bug_report_history '
            . '(history_id,report_id,actor_user_id,event_type,visibility,payload_json,created_at_utc) '
            . 'VALUES (:history_id,:report_id,:actor_user_id,:event_type,:visibility,:payload_json,:created_at)',
            [
                'history_id'=>$entry->historyId->value(),
                'report_id'=>$entry->reportId->value(),
                'actor_user_id'=>$entry->actorUserId?->value(),
                'event_type'=>$entry->eventType->value,
                'visibility'=>$entry->visibility->value,
                'payload_json'=>$payload,
                'created_at'=>self::format($entry->createdAt),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new BugReportOperationException('Bug report history entry was not persisted.');
        }
    }

    public function history(EntityId $reportId, bool $includeStaff, int $limit = 200): array
    {
        self::assertLimit($limit, 500);
        $sql = 'SELECT history_id,report_id,actor_user_id,event_type,visibility,payload_json,created_at_utc '
            . 'FROM forwext_bug_report_history WHERE report_id=:report_id';
        if (!$includeStaff) {
            $sql .= " AND visibility='public'";
        }
        $sql .= ' ORDER BY created_at_utc ASC,history_id ASC LIMIT ' . $limit;

        $rows = $this->database->fetchAll(new CompiledQuery($sql, ['report_id'=>$reportId->value()]));
        return array_map($this->hydrateHistory(...), $rows);
    }

    /** @param array<string,string|int|bool|null> $parameters */
    private function optimisticUpdate(string $sql, array $parameters, EntityId $reportId): void
    {
        $affected = $this->database->execute(new CompiledQuery($sql, $parameters, true));
        if ($affected === 1) {
            return;
        }
        if ($affected > 1) {
            throw new BugReportOperationException('Bug report mutation affected multiple rows.');
        }
        if ($this->find($reportId) === null) {
            throw new BugReportNotFoundException('Bug report was not found.');
        }
        throw new BugReportOperationException('Bug report changed concurrently; reload before retrying.');
    }

    private function required(EntityId $reportId): BugReport
    {
        return $this->find($reportId)
            ?? throw new BugReportNotFoundException('Bug report was not found.');
    }

    private function reportSelect(): string
    {
        return 'SELECT report_id,category_key,reporter_user_id,assigned_user_id,title,summary,severity,status,'
            . 'finalized_at_utc,created_at_utc,updated_at_utc,version FROM forwext_bug_reports';
    }

    /** @param array<string,mixed> $row */
    private function hydrateCategory(array $row): BugReportCategory
    {
        try {
            $severity = BugReportSeverity::from((string) $row['default_severity']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored bug report category severity is invalid.', previous: $exception);
        }
        return new BugReportCategory(
            (string) $row['category_key'],
            (string) $row['label'],
            (string) ($row['description'] ?? ''),
            $severity,
            (int) $row['sort_order'],
            (bool) $row['active'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateReport(array $row): BugReport
    {
        try {
            $severity = BugReportSeverity::from((string) $row['severity']);
            $status = BugReportStatus::from((string) $row['status']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored bug report severity/status is invalid.', previous: $exception);
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
            self::parseNullable($row['finalized_at_utc']),
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
            (int) $row['version'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateHistory(array $row): BugReportHistoryEntry
    {
        try {
            $event = BugHistoryEventType::from((string) $row['event_type']);
            $visibility = BugHistoryVisibility::from((string) $row['visibility']);
            $payload = json_decode((string) $row['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (ValueError|JsonException $exception) {
            throw new RuntimeException('Stored bug report history is invalid.', previous: $exception);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Stored bug report history payload is invalid.');
        }

        return new BugReportHistoryEntry(
            EntityId::fromString((string) $row['history_id']),
            EntityId::fromString((string) $row['report_id']),
            $row['actor_user_id'] === null ? null : UserId::fromStored((string) $row['actor_user_id']),
            $event,
            $visibility,
            $payload,
            self::parse((string) $row['created_at_utc']),
        );
    }

    private static function assertLimit(int $limit, int $max): void
    {
        if ($limit < 1 || $limit > $max) {
            throw new BugReportOperationException('Bug report list limit is invalid.');
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function formatNullable(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : self::format($value);
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored bug report timestamp is invalid.');
        }
        return $time;
    }

    private static function parseNullable(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? self::parse($value) : null;
    }
}
