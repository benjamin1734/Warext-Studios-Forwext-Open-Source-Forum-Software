<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseOversightReviewRepository implements OversightReviewRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function insertCase(OversightReviewCase $case): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            "INSERT INTO forwext_moderation_oversight_review_cases "
            . "(case_id,source_audit_id,opened_by_user_id,summary,status,opened_at_utc,resolved_at_utc,resolved_by_user_id,resolution) "
            . "VALUES (:case_id,:source_audit_id,:opened_by,:summary,:status,:opened_at,NULL,NULL,NULL)",
            [
                'case_id'=>$case->caseId->value(),
                'source_audit_id'=>$case->sourceAuditId->value(),
                'opened_by'=>$case->openedByUserId->value(),
                'summary'=>$case->summary,
                'status'=>$case->status->value,
                'opened_at'=>self::format($case->openedAt),
            ],
        ));
        if ($affected !== 1) {
            throw new OversightOperationException('Oversight review case was not persisted.');
        }
    }

    public function reviewCase(EntityId $caseId): ?OversightReviewCase
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->caseSelect() . ' WHERE case_id=:case_id LIMIT 1',
            ['case_id'=>$caseId->value()],
        ));
        return $row === null ? null : $this->hydrateCase($row);
    }

    public function openCases(int $limit = 100): array
    {
        self::assertLimit($limit);
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->caseSelect() . " WHERE status='open' ORDER BY opened_at_utc DESC,case_id DESC LIMIT " . $limit,
        ));
        return array_map($this->hydrateCase(...), $rows);
    }

    public function resolveCase(
        EntityId $caseId,
        EntityId $actorUserId,
        string $resolution,
        DateTimeImmutable $at,
    ): void {
        UserId::assert($actorUserId);
        $resolution = self::resolution($resolution);
        $affected = $this->database->execute(new CompiledQuery(
            "UPDATE forwext_moderation_oversight_review_cases SET status='resolved',resolved_at_utc=:resolved_at,"
            . "resolved_by_user_id=:resolved_by,resolution=:resolution "
            . "WHERE case_id=:case_id AND status='open'",
            [
                'resolved_at'=>self::format($at),
                'resolved_by'=>$actorUserId->value(),
                'resolution'=>$resolution,
                'case_id'=>$caseId->value(),
            ],
        ));
        if ($affected !== 1) {
            throw new OversightOperationException('Oversight review case is unavailable or already resolved.');
        }
    }

    public function insertFlag(OversightAnomalyFlag $flag): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            "INSERT INTO forwext_moderation_oversight_anomaly_flags "
            . "(flag_id,source_audit_id,flag_type,severity,details,created_at_utc,resolved_at_utc,resolved_by_user_id,resolution) "
            . "VALUES (:flag_id,:source_audit_id,:flag_type,:severity,:details,:created_at,NULL,NULL,NULL) "
            . "ON DUPLICATE KEY UPDATE flag_id=flag_id",
            [
                'flag_id'=>$flag->flagId->value(),
                'source_audit_id'=>$flag->sourceAuditId->value(),
                'flag_type'=>$flag->flagType,
                'severity'=>$flag->severity->value,
                'details'=>$flag->details,
                'created_at'=>self::format($flag->createdAt),
            ],
        ));
        if ($affected !== 1) {
            throw new OversightOperationException('Oversight anomaly flag already exists or could not be persisted.');
        }
    }

    public function flag(EntityId $flagId): ?OversightAnomalyFlag
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->flagSelect() . ' WHERE flag_id=:flag_id LIMIT 1',
            ['flag_id'=>$flagId->value()],
        ));
        return $row === null ? null : $this->hydrateFlag($row);
    }

    public function openFlags(int $limit = 100): array
    {
        self::assertLimit($limit);
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->flagSelect() . ' WHERE resolved_at_utc IS NULL '
            . 'ORDER BY created_at_utc DESC,flag_id DESC LIMIT ' . $limit,
        ));
        return array_map($this->hydrateFlag(...), $rows);
    }

    public function resolveFlag(
        EntityId $flagId,
        EntityId $actorUserId,
        string $resolution,
        DateTimeImmutable $at,
    ): void {
        UserId::assert($actorUserId);
        $resolution = self::resolution($resolution);
        $affected = $this->database->execute(new CompiledQuery(
            "UPDATE forwext_moderation_oversight_anomaly_flags "
            . "SET resolved_at_utc=:resolved_at,resolved_by_user_id=:resolved_by,resolution=:resolution "
            . "WHERE flag_id=:flag_id AND resolved_at_utc IS NULL",
            [
                'resolved_at'=>self::format($at),
                'resolved_by'=>$actorUserId->value(),
                'resolution'=>$resolution,
                'flag_id'=>$flagId->value(),
            ],
        ));
        if ($affected !== 1) {
            throw new OversightOperationException('Oversight anomaly flag is unavailable or already resolved.');
        }
    }

    private function caseSelect(): string
    {
        return 'SELECT case_id,source_audit_id,opened_by_user_id,summary,status,opened_at_utc,resolved_at_utc,'
            . 'resolved_by_user_id,resolution FROM forwext_moderation_oversight_review_cases';
    }

    private function flagSelect(): string
    {
        return 'SELECT flag_id,source_audit_id,flag_type,severity,details,created_at_utc,resolved_at_utc,'
            . 'resolved_by_user_id,resolution FROM forwext_moderation_oversight_anomaly_flags';
    }

    /** @param array<string,mixed> $row */
    private function hydrateCase(array $row): OversightReviewCase
    {
        return new OversightReviewCase(
            EntityId::fromString((string) ($row['case_id'] ?? '')),
            EntityId::fromString((string) ($row['source_audit_id'] ?? '')),
            UserId::fromStored((string) ($row['opened_by_user_id'] ?? '')),
            (string) ($row['summary'] ?? ''),
            OversightReviewStatus::from((string) ($row['status'] ?? '')),
            self::parse((string) ($row['opened_at_utc'] ?? '')),
            is_string($row['resolved_at_utc'] ?? null) ? self::parse($row['resolved_at_utc']) : null,
            is_string($row['resolved_by_user_id'] ?? null) ? UserId::fromStored($row['resolved_by_user_id']) : null,
            is_string($row['resolution'] ?? null) ? $row['resolution'] : null,
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateFlag(array $row): OversightAnomalyFlag
    {
        return new OversightAnomalyFlag(
            EntityId::fromString((string) ($row['flag_id'] ?? '')),
            EntityId::fromString((string) ($row['source_audit_id'] ?? '')),
            (string) ($row['flag_type'] ?? ''),
            OversightAnomalySeverity::from((string) ($row['severity'] ?? '')),
            (string) ($row['details'] ?? ''),
            self::parse((string) ($row['created_at_utc'] ?? '')),
            is_string($row['resolved_at_utc'] ?? null) ? self::parse($row['resolved_at_utc']) : null,
            is_string($row['resolved_by_user_id'] ?? null) ? UserId::fromStored($row['resolved_by_user_id']) : null,
            is_string($row['resolution'] ?? null) ? $row['resolution'] : null,
        );
    }

    private static function resolution(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 1000) {
            throw new InvalidArgumentException('Oversight resolution must contain 1-1000 bytes.');
        }
        return $value;
    }

    private static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Oversight review list limit is invalid.');
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored oversight review timestamp is invalid.');
        }
        return $time;
    }
}
