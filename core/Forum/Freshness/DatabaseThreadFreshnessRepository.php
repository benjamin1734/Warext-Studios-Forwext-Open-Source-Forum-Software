<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Freshness;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class DatabaseThreadFreshnessRepository implements ThreadFreshnessRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function policy(EntityId $forumNodeId): ?ThreadFreshnessPolicy
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT forum_node_id,enabled,stale_after_days,notify_after_days,auto_lock_after_days,'
            . 'auto_archive_after_days,auto_unfeature_after_days,moderator_review_after_days,renewal_cooldown_hours '
            . 'FROM forwext_thread_freshness_policies WHERE forum_node_id=:forum_node_id LIMIT 1',
            ['forum_node_id'=>$forumNodeId->value()],
        ));
        return $row === null ? null : $this->policyFromRow($row);
    }

    public function savePolicy(ThreadFreshnessPolicy $policy, DateTimeImmutable $at): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_thread_freshness_policies '
            . '(forum_node_id,enabled,stale_after_days,notify_after_days,auto_lock_after_days,'
            . 'auto_archive_after_days,auto_unfeature_after_days,moderator_review_after_days,'
            . 'renewal_cooldown_hours,updated_at_utc) '
            . 'VALUES (:forum_node_id,:enabled,:stale_after_days,:notify_after_days,:auto_lock_after_days,'
            . ':auto_archive_after_days,:auto_unfeature_after_days,:moderator_review_after_days,'
            . ':renewal_cooldown_hours,:updated_at_utc) '
            . 'ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),stale_after_days=VALUES(stale_after_days),'
            . 'notify_after_days=VALUES(notify_after_days),auto_lock_after_days=VALUES(auto_lock_after_days),'
            . 'auto_archive_after_days=VALUES(auto_archive_after_days),'
            . 'auto_unfeature_after_days=VALUES(auto_unfeature_after_days),'
            . 'moderator_review_after_days=VALUES(moderator_review_after_days),'
            . 'renewal_cooldown_hours=VALUES(renewal_cooldown_hours),updated_at_utc=VALUES(updated_at_utc)',
            [
                'forum_node_id'=>$policy->forumNodeId->value(),
                'enabled'=>$policy->enabled ? 1 : 0,
                'stale_after_days'=>$policy->staleAfterDays,
                'notify_after_days'=>$policy->notifyAfterDays,
                'auto_lock_after_days'=>$policy->autoLockAfterDays,
                'auto_archive_after_days'=>$policy->autoArchiveAfterDays,
                'auto_unfeature_after_days'=>$policy->autoUnfeatureAfterDays,
                'moderator_review_after_days'=>$policy->moderatorReviewAfterDays,
                'renewal_cooldown_hours'=>$policy->renewalCooldownHours,
                'updated_at_utc'=>self::format($at),
            ],
        ));
    }

    public function snapshot(EntityId $threadId, DateTimeImmutable $now): ?ThreadFreshnessSnapshot
    {
        $this->ensureState($threadId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT t.thread_id,t.forum_node_id,t.author_user_id,t.title,t.locked,t.featured,t.archived,'
            . 's.last_activity_at_utc,s.last_renewed_at_utc,s.renew_count,s.notified_at_utc,'
            . 's.review_requested_at_utc,s.auto_locked_at_utc,s.auto_archived_at_utc,s.auto_unfeatured_at_utc,'
            . 'p.stale_after_days '
            . 'FROM forwext_threads t '
            . 'INNER JOIN forwext_thread_freshness_state s ON s.thread_id=t.thread_id '
            . 'LEFT JOIN forwext_thread_freshness_policies p ON p.forum_node_id=t.forum_node_id AND p.enabled=1 '
            . 'WHERE t.thread_id=:thread_id AND t.deleted=0 AND t.merged_into_thread_id IS NULL LIMIT 1',
            ['thread_id'=>$threadId->value()],
        ));
        if ($row === null) {
            return null;
        }
        $activity = self::date((string) $row['last_activity_at_utc']);
        $ageDays = max(0, (int) floor(($now->getTimestamp() - $activity->getTimestamp()) / 86400));
        $staleDays = isset($row['stale_after_days']) ? (int) $row['stale_after_days'] : PHP_INT_MAX;
        return new ThreadFreshnessSnapshot(
            EntityId::fromString((string) $row['thread_id']),
            EntityId::fromString((string) $row['forum_node_id']),
            is_string($row['author_user_id'] ?? null) && $row['author_user_id'] !== ''
                ? EntityId::fromString((string) $row['author_user_id']) : null,
            (string) $row['title'],
            $activity,
            $ageDays,
            $ageDays >= $staleDays,
            (bool) $row['archived'],
            (bool) $row['locked'],
            (bool) $row['featured'],
            self::nullableDate($row['last_renewed_at_utc'] ?? null),
            (int) ($row['renew_count'] ?? 0),
            self::nullableDate($row['notified_at_utc'] ?? null),
            self::nullableDate($row['review_requested_at_utc'] ?? null),
            self::nullableDate($row['auto_locked_at_utc'] ?? null),
            self::nullableDate($row['auto_archived_at_utc'] ?? null),
            self::nullableDate($row['auto_unfeatured_at_utc'] ?? null),
        );
    }

    public function maintenanceThreadIds(DateTimeImmutable $now, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Freshness maintenance limit must be between 1 and 500.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT t.thread_id FROM forwext_threads t '
            . 'INNER JOIN forwext_thread_freshness_state s ON s.thread_id=t.thread_id '
            . 'INNER JOIN forwext_thread_freshness_policies p ON p.forum_node_id=t.forum_node_id AND p.enabled=1 '
            . 'WHERE t.deleted=0 AND t.merged_into_thread_id IS NULL '
            . 'AND TIMESTAMPDIFF(DAY,s.last_activity_at_utc,:now_utc) >= '
            . 'LEAST(p.stale_after_days,COALESCE(p.notify_after_days,p.stale_after_days)) '
            . 'AND (s.last_evaluated_at_utc IS NULL OR s.last_evaluated_at_utc <= :reevaluate_before) '
            . 'ORDER BY COALESCE(s.last_evaluated_at_utc,\'1970-01-01 00:00:00.000000\') ASC,'
            . 's.last_activity_at_utc ASC,t.thread_id ASC LIMIT ' . $limit,
            [
                'now_utc'=>self::format($now),
                'reevaluate_before'=>self::format($now->modify('-1 hour')),
            ],
        ));
        $ids = [];
        foreach ($rows as $row) {
            if (!is_string($row['thread_id'] ?? null)) {
                throw new ThreadFreshnessException('Freshness maintenance row is malformed.');
            }
            $ids[] = EntityId::fromString((string) $row['thread_id']);
        }
        return $ids;
    }

    public function markEvaluated(EntityId $threadId, DateTimeImmutable $at): void
    {
        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_thread_freshness_state SET last_evaluated_at_utc=:at '
            . 'WHERE thread_id=:thread_id',
            ['at'=>self::format($at),'thread_id'=>$threadId->value()],
        ));
    }

    public function markNotified(EntityId $threadId, DateTimeImmutable $at): bool
    {
        return $this->database->execute(new CompiledQuery(
            'UPDATE forwext_thread_freshness_state SET notified_at_utc=:at '
            . 'WHERE thread_id=:thread_id AND notified_at_utc IS NULL',
            ['at'=>self::format($at),'thread_id'=>$threadId->value()],
        )) === 1;
    }

    public function autoLock(EntityId $threadId, DateTimeImmutable $at): bool
    {
        return $this->database->transaction(function () use ($threadId,$at): bool {
            $affected = $this->database->execute(new CompiledQuery(
                'UPDATE forwext_threads SET locked=1,version=version+1,updated_at_utc=:at '
                . 'WHERE thread_id=:thread_id AND locked=0 AND deleted=0 AND merged_into_thread_id IS NULL',
                ['at'=>self::format($at),'thread_id'=>$threadId->value()],
                true,
            ));
            if ($affected === 1) {
                $this->database->execute(new CompiledQuery(
                    'UPDATE forwext_thread_freshness_state SET auto_locked_at_utc=COALESCE(auto_locked_at_utc,:at) '
                    . 'WHERE thread_id=:thread_id',
                    ['at'=>self::format($at),'thread_id'=>$threadId->value()],
                    true,
                ));
            }
            return $affected === 1;
        });
    }

    public function autoArchive(EntityId $threadId, DateTimeImmutable $at): bool
    {
        return $this->database->transaction(function () use ($threadId,$at): bool {
            $before = $this->database->fetchOne(new CompiledQuery(
                'SELECT locked FROM forwext_threads WHERE thread_id=:thread_id AND archived=0 '
                . 'AND deleted=0 AND merged_into_thread_id IS NULL LIMIT 1 FOR UPDATE',
                ['thread_id'=>$threadId->value()],
                true,
            ));
            if ($before === null) {
                return false;
            }
            $wasLocked = (bool) ($before['locked'] ?? false);
            $affected = $this->database->execute(new CompiledQuery(
                'UPDATE forwext_threads SET archived=1,archived_at_utc=:at,locked=1,version=version+1,updated_at_utc=:at '
                . 'WHERE thread_id=:thread_id AND archived=0 AND deleted=0 AND merged_into_thread_id IS NULL',
                ['at'=>self::format($at),'thread_id'=>$threadId->value()],
                true,
            ));
            if ($affected === 1) {
                $this->database->execute(new CompiledQuery(
                    'UPDATE forwext_thread_freshness_state SET auto_archived_at_utc=COALESCE(auto_archived_at_utc,:at),'
                    . 'auto_locked_at_utc=CASE WHEN :was_locked=0 THEN COALESCE(auto_locked_at_utc,:at) ELSE auto_locked_at_utc END '
                    . 'WHERE thread_id=:thread_id',
                    [
                        'at'=>self::format($at),
                        'was_locked'=>$wasLocked ? 1 : 0,
                        'thread_id'=>$threadId->value(),
                    ],
                    true,
                ));
            }
            return $affected === 1;
        });
    }

    public function autoUnfeature(EntityId $threadId, DateTimeImmutable $at): bool
    {
        return $this->database->transaction(function () use ($threadId,$at): bool {
            $affected = $this->database->execute(new CompiledQuery(
                'UPDATE forwext_threads SET featured=0,version=version+1,updated_at_utc=:at '
                . 'WHERE thread_id=:thread_id AND featured=1 AND deleted=0 AND merged_into_thread_id IS NULL',
                ['at'=>self::format($at),'thread_id'=>$threadId->value()],
                true,
            ));
            if ($affected === 1) {
                $this->database->execute(new CompiledQuery(
                    'UPDATE forwext_thread_freshness_state SET auto_unfeatured_at_utc=COALESCE(auto_unfeatured_at_utc,:at) '
                    . 'WHERE thread_id=:thread_id',
                    ['at'=>self::format($at),'thread_id'=>$threadId->value()],
                    true,
                ));
            }
            return $affected === 1;
        });
    }

    public function requestReview(EntityId $threadId, DateTimeImmutable $at): bool
    {
        return $this->database->transaction(function () use ($threadId,$at): bool {
            $affected = $this->database->execute(new CompiledQuery(
                'UPDATE forwext_thread_freshness_state SET review_requested_at_utc=:at '
                . 'WHERE thread_id=:thread_id AND review_requested_at_utc IS NULL',
                ['at'=>self::format($at),'thread_id'=>$threadId->value()],
                true,
            ));
            if ($affected !== 1) {
                return false;
            }
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_thread_freshness_reviews '
                . '(review_id,thread_id,status,requested_at_utc) VALUES (:review_id,:thread_id,\'pending\',:requested_at_utc)',
                [
                    'review_id'=>bin2hex(random_bytes(16)),
                    'thread_id'=>$threadId->value(),
                    'requested_at_utc'=>self::format($at),
                ],
                true,
            ));
            return true;
        });
    }

    public function pendingReviews(DateTimeImmutable $now, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Freshness review limit must be between 1 and 500.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT r.thread_id,r.requested_at_utc,r.status,r.resolved_by_user_id,r.resolved_at_utc,r.resolution,'
            . 't.forum_node_id,t.author_user_id,t.title,s.last_activity_at_utc '
            . 'FROM forwext_thread_freshness_reviews r '
            . 'INNER JOIN forwext_threads t ON t.thread_id=r.thread_id '
            . 'INNER JOIN forwext_thread_freshness_state s ON s.thread_id=r.thread_id '
            . 'WHERE r.status=\'pending\' ORDER BY r.requested_at_utc ASC LIMIT ' . $limit,
        ));
        $reviews = [];
        foreach ($rows as $row) {
            $activity = self::date((string) $row['last_activity_at_utc']);
            $reviews[] = new ThreadFreshnessReview(
                EntityId::fromString((string) $row['thread_id']),
                EntityId::fromString((string) $row['forum_node_id']),
                is_string($row['author_user_id'] ?? null) && $row['author_user_id'] !== ''
                    ? EntityId::fromString((string) $row['author_user_id']) : null,
                (string) $row['title'],
                max(0,(int) floor(($now->getTimestamp()-$activity->getTimestamp())/86400)),
                self::date((string) $row['requested_at_utc']),
                (string) $row['status'],
                is_string($row['resolved_by_user_id'] ?? null) && $row['resolved_by_user_id'] !== ''
                    ? EntityId::fromString((string) $row['resolved_by_user_id']) : null,
                self::nullableDate($row['resolved_at_utc'] ?? null),
                is_string($row['resolution'] ?? null) ? (string) $row['resolution'] : null,
            );
        }
        return $reviews;
    }

    public function resolveReview(
        EntityId $threadId,
        EntityId $actorUserId,
        string $resolution,
        DateTimeImmutable $at,
    ): bool {
        if (preg_match('/^[a-z][a-z0-9._-]{0,31}$/D', $resolution) !== 1) {
            throw new InvalidArgumentException('Freshness review resolution is invalid.');
        }
        return $this->database->transaction(function () use ($threadId,$actorUserId,$resolution,$at): bool {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT review_id FROM forwext_thread_freshness_reviews '
                . 'WHERE thread_id=:thread_id AND status=\'pending\' ORDER BY requested_at_utc DESC LIMIT 1 FOR UPDATE',
                ['thread_id'=>$threadId->value()],
                true,
            ));
            if (!is_string($row['review_id'] ?? null)) {
                return false;
            }
            $affected = $this->database->execute(new CompiledQuery(
                'UPDATE forwext_thread_freshness_reviews SET status=\'resolved\',resolved_by_user_id=:actor_user_id,'
                . 'resolved_at_utc=:at,resolution=:resolution WHERE review_id=:review_id AND status=\'pending\'',
                [
                    'actor_user_id'=>$actorUserId->value(),
                    'at'=>self::format($at),
                    'resolution'=>$resolution,
                    'review_id'=>(string) $row['review_id'],
                ],
                true,
            ));
            return $affected === 1;
        });
    }

    public function renew(
        EntityId $threadId,
        EntityId $actorUserId,
        DateTimeImmutable $at,
        bool $reopenArchived,
    ): void {
        $this->database->transaction(function () use ($threadId,$actorUserId,$at,$reopenArchived): void {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT auto_locked_at_utc,auto_archived_at_utc FROM forwext_thread_freshness_state '
                . 'WHERE thread_id=:thread_id LIMIT 1 FOR UPDATE',
                ['thread_id'=>$threadId->value()],
                true,
            ));
            if ($row === null) {
                throw new ThreadFreshnessException('Freshness state is unavailable.');
            }

            $this->database->execute(new CompiledQuery(
                'UPDATE forwext_thread_freshness_state SET last_activity_at_utc=:at,last_renewed_at_utc=:at,'
                . 'renew_count=renew_count+1,notified_at_utc=NULL,review_requested_at_utc=NULL,last_evaluated_at_utc=NULL '
                . 'WHERE thread_id=:thread_id',
                ['at'=>self::format($at),'thread_id'=>$threadId->value()],
                true,
            ));

            if ($reopenArchived && ($row['auto_archived_at_utc'] ?? null) !== null) {
                $unlock = ($row['auto_locked_at_utc'] ?? null) !== null;
                $this->database->execute(new CompiledQuery(
                    'UPDATE forwext_threads SET archived=0,archived_at_utc=NULL,locked=:locked,'
                    . 'version=version+1,updated_at_utc=:at WHERE thread_id=:thread_id',
                    [
                        'locked'=>$unlock ? 0 : 1,
                        'at'=>self::format($at),
                        'thread_id'=>$threadId->value(),
                    ],
                    true,
                ));
                $this->database->execute(new CompiledQuery(
                    'UPDATE forwext_thread_freshness_state SET auto_archived_at_utc=NULL,'
                    . 'auto_locked_at_utc=CASE WHEN :unlock=1 THEN NULL ELSE auto_locked_at_utc END '
                    . 'WHERE thread_id=:thread_id',
                    ['unlock'=>$unlock ? 1 : 0,'thread_id'=>$threadId->value()],
                    true,
                ));
            } elseif (($row['auto_locked_at_utc'] ?? null) !== null) {
                $this->database->execute(new CompiledQuery(
                    'UPDATE forwext_threads SET locked=0,version=version+1,updated_at_utc=:at '
                    . 'WHERE thread_id=:thread_id AND archived=0',
                    ['at'=>self::format($at),'thread_id'=>$threadId->value()],
                    true,
                ));
                $this->database->execute(new CompiledQuery(
                    'UPDATE forwext_thread_freshness_state SET auto_locked_at_utc=NULL WHERE thread_id=:thread_id',
                    ['thread_id'=>$threadId->value()],
                    true,
                ));
            }

            $this->database->execute(new CompiledQuery(
                'UPDATE forwext_thread_freshness_reviews SET status=\'resolved\',resolved_by_user_id=:actor_user_id,'
                . 'resolved_at_utc=:at,resolution=\'renewed\' WHERE thread_id=:thread_id AND status=\'pending\'',
                [
                    'actor_user_id'=>$actorUserId->value(),
                    'at'=>self::format($at),
                    'thread_id'=>$threadId->value(),
                ],
                true,
            ));
        });
    }

    public function postIds(EntityId $threadId, int $limit = 10000): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new InvalidArgumentException('Freshness related post limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT post_id FROM forwext_posts WHERE thread_id=:thread_id ORDER BY position ASC LIMIT ' . $limit,
            ['thread_id'=>$threadId->value()],
        ));
        $ids = [];
        foreach ($rows as $row) {
            if (!is_string($row['post_id'] ?? null)) {
                throw new ThreadFreshnessException('Freshness related post row is malformed.');
            }
            $ids[] = EntityId::fromString((string) $row['post_id']);
        }
        return $ids;
    }

    private function ensureState(EntityId $threadId): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT IGNORE INTO forwext_thread_freshness_state '
            . '(thread_id,last_activity_at_utc,renew_count) '
            . 'SELECT t.thread_id,GREATEST(t.created_at_utc,COALESCE(MAX(p.updated_at_utc),t.updated_at_utc)),0 '
            . 'FROM forwext_threads t LEFT JOIN forwext_posts p ON p.thread_id=t.thread_id '
            . 'WHERE t.thread_id=:thread_id GROUP BY t.thread_id,t.created_at_utc,t.updated_at_utc',
            ['thread_id'=>$threadId->value()],
        ));
    }

    /** @param array<string,mixed> $row */
    private function policyFromRow(array $row): ThreadFreshnessPolicy
    {
        return new ThreadFreshnessPolicy(
            EntityId::fromString((string) $row['forum_node_id']),
            (bool) $row['enabled'],
            (int) $row['stale_after_days'],
            self::nullableInt($row['notify_after_days'] ?? null),
            self::nullableInt($row['auto_lock_after_days'] ?? null),
            self::nullableInt($row['auto_archive_after_days'] ?? null),
            self::nullableInt($row['auto_unfeature_after_days'] ?? null),
            self::nullableInt($row['moderator_review_after_days'] ?? null),
            (int) ($row['renewal_cooldown_hours'] ?? 24),
        );
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? self::date($value) : null;
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new ThreadFreshnessException('Stored freshness timestamp is invalid.');
        }
        return $date;
    }
}
