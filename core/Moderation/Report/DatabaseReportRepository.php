<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;
use ValueError;

final readonly class DatabaseReportRepository implements ReportRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function activeReasons(): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `reason_key`,`label`,`description`,`sort_order`,`active` FROM `forwext_report_reasons` '
            . 'WHERE `active` = 1 ORDER BY `sort_order` ASC, `reason_key` ASC',
        ));
        return array_map($this->hydrateReason(...), $rows);
    }

    public function reason(string $reasonKey): ?ReportReason
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `reason_key`,`label`,`description`,`sort_order`,`active` FROM `forwext_report_reasons` '
            . 'WHERE `reason_key` = :reason_key LIMIT 1',
            ['reason_key' => $reasonKey],
        ));
        return $row === null ? null : $this->hydrateReason($row);
    }

    public function findOrCreateActiveGroup(
        ReportableContent $content,
        ReportReason $reason,
        DateTimeImmutable $now,
    ): ReportGroupMatch {
        if (!$this->database->inTransaction()) {
            throw new RuntimeException('Report duplicate grouping requires an active transaction.');
        }
        $dedupeKey = hash('sha256', $content->targetType . "\0" . $content->targetId->value() . "\0" . $reason->key);
        $groupId = EntityId::fromString(bin2hex(random_bytes(16)));
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT IGNORE INTO `forwext_report_groups` '
            . '(`group_id`,`target_type`,`target_id`,`target_title_snapshot`,`reason_key`,`reason_label_snapshot`,'
            . '`status`,`assigned_moderator_user_id`,`report_count`,`active_dedupe_key`,`created_at_utc`,`updated_at_utc`,`latest_report_at_utc`) '
            . "VALUES (:group_id,:target_type,:target_id,:target_title,:reason_key,:reason_label,'open',NULL,1,:dedupe_key,:created_at,:updated_at,:latest_at)",
            [
                'group_id' => $groupId->value(),
                'target_type' => $content->targetType,
                'target_id' => $content->targetId->value(),
                'target_title' => $content->title,
                'reason_key' => $reason->key,
                'reason_label' => $reason->label,
                'dedupe_key' => $dedupeKey,
                'created_at' => self::format($now),
                'updated_at' => self::format($now),
                'latest_at' => self::format($now),
            ],
        ));

        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM `forwext_report_groups` WHERE `active_dedupe_key` = :dedupe_key LIMIT 1 FOR UPDATE',
            ['dedupe_key' => $dedupeKey],
        ));
        if ($row === null) {
            throw new RuntimeException('Active report group disappeared during duplicate grouping.');
        }
        return new ReportGroupMatch($this->hydrateGroup($row), $affected === 1);
    }

    public function addSubmission(
        EntityId $groupId,
        EntityId $reporterUserId,
        string $detail,
        bool $groupCountAlreadyIncludesSubmission,
        DateTimeImmutable $now,
    ): ReportReceipt {
        UserId::assert($reporterUserId);
        if (!$this->database->inTransaction()) {
            throw new RuntimeException('Report submissions require an active transaction.');
        }
        $detail = trim($detail);
        if (strlen($detail) > 2000) {
            throw new RuntimeException('Report detail exceeds the storage limit.');
        }

        $reportId = EntityId::fromString(bin2hex(random_bytes(16)));
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT IGNORE INTO `forwext_reports` '
            . '(`report_id`,`group_id`,`reporter_user_id`,`detail`,`created_at_utc`) '
            . 'VALUES (:report_id,:group_id,:reporter_user_id,:detail,:created_at)',
            [
                'report_id' => $reportId->value(),
                'group_id' => $groupId->value(),
                'reporter_user_id' => $reporterUserId->value(),
                'detail' => $detail,
                'created_at' => self::format($now),
            ],
        ));

        if ($affected === 1) {
            if (!$groupCountAlreadyIncludesSubmission) {
                $updated = $this->database->execute(new CompiledQuery(
                    'UPDATE `forwext_report_groups` SET `report_count` = `report_count` + 1, '
                    . '`latest_report_at_utc` = :latest_at, `updated_at_utc` = :updated_at '
                    . 'WHERE `group_id` = :group_id',
                    [
                        'latest_at' => self::format($now),
                        'updated_at' => self::format($now),
                        'group_id' => $groupId->value(),
                    ],
                ));
                if ($updated !== 1) {
                    throw new RuntimeException('Report group count could not be updated.');
                }
            }
            return new ReportReceipt($reportId, $groupId, true, !$groupCountAlreadyIncludesSubmission);
        }

        $stored = $this->database->fetchValue(new CompiledQuery(
            'SELECT `report_id` FROM `forwext_reports` '
            . 'WHERE `group_id` = :group_id AND `reporter_user_id` = :reporter_user_id LIMIT 1',
            ['group_id' => $groupId->value(), 'reporter_user_id' => $reporterUserId->value()],
        ));
        if (!is_string($stored) || $stored === '') {
            throw new RuntimeException('Duplicate report submission could not be resolved.');
        }
        return new ReportReceipt(EntityId::fromString($stored), $groupId, false, true);
    }

    public function findGroup(EntityId $groupId): ?ReportGroup
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM `forwext_report_groups` WHERE `group_id` = :group_id LIMIT 1',
            ['group_id' => $groupId->value()],
        ));
        return $row === null ? null : $this->hydrateGroup($row);
    }

    public function activeGroups(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new RuntimeException('Report group list limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM `forwext_report_groups` WHERE `status` IN (\'open\',\'in_review\') '
            . "ORDER BY FIELD(`status`,'open','in_review'), `latest_report_at_utc` DESC, `group_id` DESC LIMIT " . $limit,
        ));
        return array_map($this->hydrateGroup(...), $rows);
    }

    public function assign(EntityId $groupId, ?EntityId $moderatorUserId, DateTimeImmutable $now): ReportGroup
    {
        if ($moderatorUserId !== null) {
            UserId::assert($moderatorUserId);
        }
        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_report_groups` SET `assigned_moderator_user_id` = :moderator_user_id, '
            . '`updated_at_utc` = :updated_at WHERE `group_id` = :group_id',
            [
                'moderator_user_id' => $moderatorUserId?->value(),
                'updated_at' => self::format($now),
                'group_id' => $groupId->value(),
            ],
        ));
        if ($affected > 1) {
            throw new RuntimeException('Report assignment affected multiple groups.');
        }
        return $this->findGroup($groupId) ?? throw new ReportGroupNotFoundException('Report group was not found.');
    }

    public function updateStatus(EntityId $groupId, ReportStatus $status, DateTimeImmutable $now): ReportGroup
    {
        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_report_groups` SET `status` = :status, '
            . '`active_dedupe_key` = CASE WHEN :active = 1 THEN `active_dedupe_key` ELSE NULL END, '
            . '`updated_at_utc` = :updated_at WHERE `group_id` = :group_id',
            [
                'status' => $status->value,
                'active' => $status->isActive() ? 1 : 0,
                'updated_at' => self::format($now),
                'group_id' => $groupId->value(),
            ],
        ));
        if ($affected > 1) {
            throw new RuntimeException('Report status update affected multiple groups.');
        }
        return $this->findGroup($groupId) ?? throw new ReportGroupNotFoundException('Report group was not found.');
    }

    public function addComment(ReportComment $comment): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_report_comments` '
            . '(`comment_id`,`group_id`,`moderator_user_id`,`body`,`created_at_utc`) '
            . 'VALUES (:comment_id,:group_id,:moderator_user_id,:body,:created_at)',
            [
                'comment_id' => $comment->commentId->value(),
                'group_id' => $comment->groupId->value(),
                'moderator_user_id' => $comment->moderatorUserId?->value(),
                'body' => $comment->body,
                'created_at' => self::format($comment->createdAt),
            ],
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Moderator report comment was not persisted.');
        }
    }

    public function comments(EntityId $groupId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new RuntimeException('Report comment list limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `comment_id`,`group_id`,`moderator_user_id`,`body`,`created_at_utc` '
            . 'FROM `forwext_report_comments` WHERE `group_id` = :group_id '
            . 'ORDER BY `created_at_utc` ASC, `comment_id` ASC LIMIT ' . $limit,
            ['group_id' => $groupId->value()],
        ));
        return array_map($this->hydrateComment(...), $rows);
    }

    public function submissions(EntityId $groupId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new RuntimeException('Report submission list limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `report_id`,`group_id`,`reporter_user_id`,`detail`,`created_at_utc` '
            . 'FROM `forwext_reports` WHERE `group_id` = :group_id '
            . 'ORDER BY `created_at_utc` ASC, `report_id` ASC LIMIT ' . $limit,
            ['group_id' => $groupId->value()],
        ));
        return array_map($this->hydrateSubmission(...), $rows);
    }

    public function reporterIds(EntityId $groupId): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT DISTINCT `reporter_user_id` FROM `forwext_reports` '
            . 'WHERE `group_id` = :group_id AND `reporter_user_id` IS NOT NULL ORDER BY `reporter_user_id` ASC',
            ['group_id' => $groupId->value()],
        ));
        $ids = [];
        foreach ($rows as $row) {
            $value = $row['reporter_user_id'] ?? null;
            if (is_string($value) && $value !== '') {
                $ids[] = UserId::fromStored($value);
            }
        }
        return $ids;
    }

    public function forReporter(EntityId $reporterUserId, int $limit = 50): array
    {
        UserId::assert($reporterUserId);
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Reporter history limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT r.`report_id`,r.`group_id`,r.`created_at_utc`,g.`target_type`,g.`target_id`,'
            . 'g.`target_title_snapshot`,g.`reason_label_snapshot`,g.`status` '
            . 'FROM `forwext_reports` r INNER JOIN `forwext_report_groups` g ON g.`group_id` = r.`group_id` '
            . 'WHERE r.`reporter_user_id` = :reporter_user_id '
            . 'ORDER BY r.`created_at_utc` DESC, r.`report_id` DESC LIMIT ' . $limit,
            ['reporter_user_id' => $reporterUserId->value()],
        ));
        return array_map($this->hydrateSummary(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrateReason(array $row): ReportReason
    {
        return new ReportReason(
            (string) $row['reason_key'],
            (string) $row['label'],
            (string) ($row['description'] ?? ''),
            (int) $row['sort_order'],
            (bool) $row['active'],
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateGroup(array $row): ReportGroup
    {
        try {
            $status = ReportStatus::from((string) $row['status']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored report group status is invalid.', previous: $exception);
        }
        return new ReportGroup(
            EntityId::fromString((string) $row['group_id']),
            (string) $row['target_type'],
            EntityId::fromString((string) $row['target_id']),
            (string) $row['target_title_snapshot'],
            (string) $row['reason_key'],
            (string) $row['reason_label_snapshot'],
            $status,
            ($row['assigned_moderator_user_id'] ?? null) === null
                ? null
                : UserId::fromStored((string) $row['assigned_moderator_user_id']),
            (int) $row['report_count'],
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
            self::parse((string) $row['latest_report_at_utc']),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateComment(array $row): ReportComment
    {
        return new ReportComment(
            EntityId::fromString((string) $row['comment_id']),
            EntityId::fromString((string) $row['group_id']),
            ($row['moderator_user_id'] ?? null) === null ? null : UserId::fromStored((string) $row['moderator_user_id']),
            (string) $row['body'],
            self::parse((string) $row['created_at_utc']),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateSubmission(array $row): ReportSubmission
    {
        return new ReportSubmission(
            EntityId::fromString((string) $row['report_id']),
            EntityId::fromString((string) $row['group_id']),
            ($row['reporter_user_id'] ?? null) === null ? null : UserId::fromStored((string) $row['reporter_user_id']),
            (string) ($row['detail'] ?? ''),
            self::parse((string) $row['created_at_utc']),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateSummary(array $row): ReportSubmissionSummary
    {
        try {
            $status = ReportStatus::from((string) $row['status']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored reporter history status is invalid.', previous: $exception);
        }
        return new ReportSubmissionSummary(
            EntityId::fromString((string) $row['report_id']),
            EntityId::fromString((string) $row['group_id']),
            (string) $row['target_type'],
            EntityId::fromString((string) $row['target_id']),
            (string) $row['target_title_snapshot'],
            (string) $row['reason_label_snapshot'],
            $status,
            self::parse((string) $row['created_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored report timestamp is invalid.');
        }
        return $date;
    }
}
