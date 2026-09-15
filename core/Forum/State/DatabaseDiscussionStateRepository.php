<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\State;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Node\ForumNodeId;
use Forwext\Core\Forum\Thread\ThreadId;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseDiscussionStateRepository implements DiscussionStateRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function draft(EntityId $userId, DraftTargetType $targetType, EntityId $targetId): ?ContentDraft
    {
        self::assertTarget($userId, $targetType, $targetId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `title_source`, `body_source`, `revision`, `updated_at_utc` '
            . 'FROM `forwext_content_drafts` WHERE `user_id` = :user_id '
            . 'AND `target_type` = :target_type AND `target_id` = :target_id LIMIT 1',
            ['user_id' => $userId->value(), 'target_type' => $targetType->value, 'target_id' => $targetId->value()],
        ));
        if ($row === null) {
            return null;
        }
        return new ContentDraft(
            $userId,
            $targetType,
            $targetId,
            ($row['title_source'] ?? null) === null ? null : (string) $row['title_source'],
            (string) $row['body_source'],
            (int) $row['revision'],
            self::parse((string) $row['updated_at_utc']),
        );
    }

    public function saveDraft(
        EntityId $userId,
        DraftTargetType $targetType,
        EntityId $targetId,
        ?string $titleSource,
        string $bodySource,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): ContentDraft {
        self::assertTarget($userId, $targetType, $targetId);
        if ($expectedRevision < 0) {
            throw new InvalidArgumentException('Expected draft revision cannot be negative.');
        }
        $at = self::utc($at);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $userId,
            $targetType,
            $targetId,
            $titleSource,
            $bodySource,
            $expectedRevision,
            $at,
        ): ContentDraft {
            $current = $database->fetchOne(new CompiledQuery(
                'SELECT `revision` FROM `forwext_content_drafts` WHERE `user_id` = :user_id '
                . 'AND `target_type` = :target_type AND `target_id` = :target_id FOR UPDATE',
                ['user_id' => $userId->value(), 'target_type' => $targetType->value, 'target_id' => $targetId->value()],
                true,
            ));
            $storedRevision = $current === null ? 0 : (int) $current['revision'];
            if ($storedRevision !== $expectedRevision) {
                throw new DraftConflictException('Draft was changed by another autosave session.');
            }

            $nextRevision = $storedRevision + 1;
            $draft = new ContentDraft($userId, $targetType, $targetId, $titleSource, $bodySource, $nextRevision, $at);
            if ($current === null) {
                $affected = $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_content_drafts` '
                    . '(`user_id`, `target_type`, `target_id`, `title_source`, `body_source`, `revision`, `updated_at_utc`) '
                    . 'VALUES (:user_id, :target_type, :target_id, :title_source, :body_source, :revision, :updated_at)',
                    [
                        'user_id' => $userId->value(), 'target_type' => $targetType->value,
                        'target_id' => $targetId->value(), 'title_source' => $draft->titleSource(),
                        'body_source' => $draft->bodySource(), 'revision' => $nextRevision,
                        'updated_at' => self::format($at),
                    ],
                ));
            } else {
                $affected = $database->execute(new CompiledQuery(
                    'UPDATE `forwext_content_drafts` SET `title_source` = :title_source, '
                    . '`body_source` = :body_source, `revision` = :next_revision, `updated_at_utc` = :updated_at '
                    . 'WHERE `user_id` = :user_id AND `target_type` = :target_type '
                    . 'AND `target_id` = :target_id AND `revision` = :expected_revision',
                    [
                        'title_source' => $draft->titleSource(), 'body_source' => $draft->bodySource(),
                        'next_revision' => $nextRevision, 'updated_at' => self::format($at),
                        'user_id' => $userId->value(), 'target_type' => $targetType->value,
                        'target_id' => $targetId->value(), 'expected_revision' => $expectedRevision,
                    ],
                ));
            }
            if ($affected !== 1) {
                throw new DraftConflictException('Draft persistence lost its revision race.');
            }
            return $draft;
        });
    }

    public function deleteDraft(EntityId $userId, DraftTargetType $targetType, EntityId $targetId): void
    {
        self::assertTarget($userId, $targetType, $targetId);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_content_drafts` WHERE `user_id` = :user_id '
            . 'AND `target_type` = :target_type AND `target_id` = :target_id',
            ['user_id' => $userId->value(), 'target_type' => $targetType->value, 'target_id' => $targetId->value()],
        ));
    }

    public function markThreadRead(EntityId $userId, EntityId $threadId, int $postPosition, DateTimeImmutable $at): void
    {
        UserId::assert($userId);
        ThreadId::assert($threadId);
        if ($postPosition < 1) {
            throw new InvalidArgumentException('Read post position must be positive.');
        }
        $latest = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COALESCE(MAX(`position`), 0) FROM `forwext_posts` '
            . "WHERE `thread_id` = :thread_id AND `deleted` = 0 AND `moderation_state` = 'visible'",
            ['thread_id' => $threadId->value()],
        ));
        if ($latest < 1 || $postPosition > $latest) {
            throw new DiscussionStateException('Read position is outside the visible thread range.');
        }
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_thread_read_state` '
            . '(`user_id`, `thread_id`, `last_read_post_position`, `last_read_at_utc`) '
            . 'VALUES (:user_id, :thread_id, :position, :read_at) '
            . 'ON DUPLICATE KEY UPDATE '
            . '`last_read_post_position` = GREATEST(`last_read_post_position`, VALUES(`last_read_post_position`)), '
            . '`last_read_at_utc` = GREATEST(`last_read_at_utc`, VALUES(`last_read_at_utc`))',
            ['user_id' => $userId->value(), 'thread_id' => $threadId->value(), 'position' => $postPosition, 'read_at' => self::format($at)],
        ));
    }

    public function markForumRead(EntityId $userId, EntityId $forumNodeId, DateTimeImmutable $at): void
    {
        UserId::assert($userId);
        ForumNodeId::assert($forumNodeId);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_forum_read_state` (`user_id`, `forum_node_id`, `marked_read_at_utc`) '
            . 'VALUES (:user_id, :forum_node_id, :marked_at) '
            . 'ON DUPLICATE KEY UPDATE `marked_read_at_utc` = GREATEST(`marked_read_at_utc`, VALUES(`marked_read_at_utc`))',
            ['user_id' => $userId->value(), 'forum_node_id' => $forumNodeId->value(), 'marked_at' => self::format($at)],
        ));
    }

    public function isThreadUnread(EntityId $userId, EntityId $threadId): bool
    {
        UserId::assert($userId);
        ThreadId::assert($threadId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT '
            . '(SELECT COALESCE(MAX(`p`.`position`), 0) FROM `forwext_posts` `p` '
            . "WHERE `p`.`thread_id` = `t`.`thread_id` AND `p`.`deleted` = 0 AND `p`.`moderation_state` = 'visible') AS `latest_position`, "
            . '(SELECT MAX(`p2`.`updated_at_utc`) FROM `forwext_posts` `p2` '
            . "WHERE `p2`.`thread_id` = `t`.`thread_id` AND `p2`.`deleted` = 0 AND `p2`.`moderation_state` = 'visible') AS `latest_activity_at`, "
            . 'COALESCE((SELECT `r`.`last_read_post_position` FROM `forwext_thread_read_state` `r` '
            . 'WHERE `r`.`user_id` = :read_user_id AND `r`.`thread_id` = `t`.`thread_id`), 0) AS `read_position`, '
            . '(SELECT `f`.`marked_read_at_utc` FROM `forwext_forum_read_state` `f` '
            . 'WHERE `f`.`user_id` = :forum_user_id AND `f`.`forum_node_id` = `t`.`forum_node_id`) AS `forum_marked_at` '
            . 'FROM `forwext_threads` `t` WHERE `t`.`thread_id` = :thread_id LIMIT 1',
            ['read_user_id' => $userId->value(), 'forum_user_id' => $userId->value(), 'thread_id' => $threadId->value()],
        ));
        if ($row === null) {
            throw new DiscussionStateException('Thread is not available for read tracking.');
        }
        $latest = (int) $row['latest_position'];
        $read = (int) $row['read_position'];
        if ($latest < 1 || $read >= $latest) {
            return false;
        }
        if (($row['forum_marked_at'] ?? null) !== null
            && ($row['latest_activity_at'] ?? null) !== null
            && self::parse((string) $row['forum_marked_at']) >= self::parse((string) $row['latest_activity_at'])
        ) {
            return false;
        }
        return true;
    }

    public function watchThread(EntityId $userId, EntityId $threadId, WatchNotificationMode $mode, DateTimeImmutable $at): void
    {
        UserId::assert($userId);
        ThreadId::assert($threadId);
        if ($mode === WatchNotificationMode::None) {
            $this->unwatchThread($userId, $threadId);
            return;
        }
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_watched_threads` (`user_id`, `thread_id`, `notification_mode`, `updated_at_utc`) '
            . 'VALUES (:user_id, :thread_id, :mode, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE `notification_mode` = VALUES(`notification_mode`), `updated_at_utc` = VALUES(`updated_at_utc`)',
            ['user_id' => $userId->value(), 'thread_id' => $threadId->value(), 'mode' => $mode->value, 'updated_at' => self::format($at)],
        ));
    }

    public function unwatchThread(EntityId $userId, EntityId $threadId): void
    {
        UserId::assert($userId);
        ThreadId::assert($threadId);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_watched_threads` WHERE `user_id` = :user_id AND `thread_id` = :thread_id',
            ['user_id' => $userId->value(), 'thread_id' => $threadId->value()],
        ));
    }

    public function threadWatch(EntityId $userId, EntityId $threadId): ?WatchNotificationMode
    {
        UserId::assert($userId);
        ThreadId::assert($threadId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `notification_mode` FROM `forwext_watched_threads` WHERE `user_id` = :user_id AND `thread_id` = :thread_id LIMIT 1',
            ['user_id' => $userId->value(), 'thread_id' => $threadId->value()],
        ));
        return $row === null ? null : WatchNotificationMode::from((string) $row['notification_mode']);
    }

    public function watchForum(EntityId $userId, EntityId $forumNodeId, WatchNotificationMode $mode, DateTimeImmutable $at): void
    {
        UserId::assert($userId);
        ForumNodeId::assert($forumNodeId);
        if ($mode === WatchNotificationMode::None) {
            $this->unwatchForum($userId, $forumNodeId);
            return;
        }
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_watched_forums` (`user_id`, `forum_node_id`, `notification_mode`, `updated_at_utc`) '
            . 'VALUES (:user_id, :forum_node_id, :mode, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE `notification_mode` = VALUES(`notification_mode`), `updated_at_utc` = VALUES(`updated_at_utc`)',
            ['user_id' => $userId->value(), 'forum_node_id' => $forumNodeId->value(), 'mode' => $mode->value, 'updated_at' => self::format($at)],
        ));
    }

    public function unwatchForum(EntityId $userId, EntityId $forumNodeId): void
    {
        UserId::assert($userId);
        ForumNodeId::assert($forumNodeId);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_watched_forums` WHERE `user_id` = :user_id AND `forum_node_id` = :forum_node_id',
            ['user_id' => $userId->value(), 'forum_node_id' => $forumNodeId->value()],
        ));
    }

    public function forumWatch(EntityId $userId, EntityId $forumNodeId): ?WatchNotificationMode
    {
        UserId::assert($userId);
        ForumNodeId::assert($forumNodeId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `notification_mode` FROM `forwext_watched_forums` WHERE `user_id` = :user_id AND `forum_node_id` = :forum_node_id LIMIT 1',
            ['user_id' => $userId->value(), 'forum_node_id' => $forumNodeId->value()],
        ));
        return $row === null ? null : WatchNotificationMode::from((string) $row['notification_mode']);
    }

    public function subscriptionPreferences(EntityId $userId): SubscriptionPreferences
    {
        UserId::assert($userId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `auto_watch_created_threads`, `auto_watch_replied_threads`, `default_thread_mode`, `default_forum_mode` '
            . 'FROM `forwext_subscription_preferences` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId->value()],
        ));
        if ($row === null) {
            return new SubscriptionPreferences();
        }
        return new SubscriptionPreferences(
            (bool) $row['auto_watch_created_threads'],
            (bool) $row['auto_watch_replied_threads'],
            WatchNotificationMode::from((string) $row['default_thread_mode']),
            WatchNotificationMode::from((string) $row['default_forum_mode']),
        );
    }

    public function saveSubscriptionPreferences(EntityId $userId, SubscriptionPreferences $preferences, DateTimeImmutable $at): void
    {
        UserId::assert($userId);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_subscription_preferences` '
            . '(`user_id`, `auto_watch_created_threads`, `auto_watch_replied_threads`, `default_thread_mode`, `default_forum_mode`, `updated_at_utc`) '
            . 'VALUES (:user_id, :auto_created, :auto_replied, :thread_mode, :forum_mode, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE `auto_watch_created_threads` = VALUES(`auto_watch_created_threads`), '
            . '`auto_watch_replied_threads` = VALUES(`auto_watch_replied_threads`), `default_thread_mode` = VALUES(`default_thread_mode`), '
            . '`default_forum_mode` = VALUES(`default_forum_mode`), `updated_at_utc` = VALUES(`updated_at_utc`)',
            [
                'user_id' => $userId->value(), 'auto_created' => $preferences->autoWatchCreatedThreads(),
                'auto_replied' => $preferences->autoWatchRepliedThreads(), 'thread_mode' => $preferences->defaultThreadMode()->value,
                'forum_mode' => $preferences->defaultForumMode()->value, 'updated_at' => self::format($at),
            ],
        ));
    }

    private static function assertTarget(EntityId $userId, DraftTargetType $targetType, EntityId $targetId): void
    {
        UserId::assert($userId);
        match ($targetType) {
            DraftTargetType::NewThread => ForumNodeId::assert($targetId),
            DraftTargetType::Reply => ThreadId::assert($targetId),
        };
    }

    private static function format(DateTimeImmutable $value): string { return self::utc($value)->format('Y-m-d H:i:s.u'); }
    private static function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) { throw new RuntimeException('Stored discussion-state timestamp is invalid.'); }
        return $parsed;
    }
    private static function utc(DateTimeImmutable $value): DateTimeImmutable { return $value->setTimezone(new DateTimeZone('UTC')); }
}
