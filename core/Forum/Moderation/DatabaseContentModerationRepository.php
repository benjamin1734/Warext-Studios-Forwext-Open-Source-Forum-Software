<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeId;
use Forwext\Core\Forum\Post\PostId;
use Forwext\Core\Forum\Post\PostModerationState;
use Forwext\Core\Forum\Thread\ThreadId;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadTitle;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseContentModerationRepository implements ContentModerationRepository
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private ModerationAuditStore $audit,
    ) {
    }

    public function thread(EntityId $threadId): ?ModerationThreadRecord
    {
        ThreadId::assert($threadId);
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->threadSelectSql() . ' WHERE `thread_id` = :thread_id LIMIT 1',
            ['thread_id' => $threadId->value()],
        ));
        return $row === null ? null : $this->threadRecord($row);
    }

    public function post(EntityId $postId): ?ModerationPostRecord
    {
        PostId::assert($postId);
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->postContextSelectSql() . ' WHERE p.`post_id` = :post_id LIMIT 1',
            ['post_id' => $postId->value()],
        ));
        return $row === null ? null : $this->postRecord($row);
    }

    public function moveThread(EntityId $threadId, EntityId $targetForumNodeId, ModerationAuditContext $context): void
    {
        ThreadId::assert($threadId);
        ForumNodeId::assert($targetForumNodeId);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $threadId,
            $targetForumNodeId,
            $context,
        ): void {
            $row = $this->requireActiveThread($database, $threadId);
            $sourceForum = ForumNodeId::fromStored((string) $row['forum_node_id']);
            if ($sourceForum->equals($targetForumNodeId)) {
                return;
            }
            $this->updateThreadFields(
                $database,
                $threadId,
                ['`forum_node_id` = :forum_node_id'],
                ['forum_node_id' => $targetForumNodeId->value()],
                $context->occurredAt,
            );
            $this->appendAudit(
                $context,
                ModerationAuditAction::ThreadMove,
                'thread',
                $threadId->value(),
                $sourceForum,
                ['forum_node_id' => $sourceForum->value()],
                ['forum_node_id' => $targetForumNodeId->value()],
            );
        });
    }

    public function copyThread(EntityId $threadId, EntityId $targetForumNodeId, ModerationAuditContext $context): EntityId
    {
        ThreadId::assert($threadId);
        ForumNodeId::assert($targetForumNodeId);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $threadId,
            $targetForumNodeId,
            $context,
        ): EntityId {
            $source = $this->requireActiveThread($database, $threadId);
            $posts = $database->fetchAll(new CompiledQuery(
                'SELECT `post_id`, `author_user_id`, `position`, `body_source`, `moderation_state`, '
                . '`deleted`, `deleted_at_utc`, `created_at_utc`, `updated_at_utc` '
                . 'FROM `forwext_posts` WHERE `thread_id` = :thread_id ORDER BY `position` ASC FOR UPDATE',
                ['thread_id' => $threadId->value()],
                true,
            ));
            if ($posts === [] || (int) ($posts[0]['position'] ?? 0) !== 1) {
                throw new RuntimeException('Thread copy requires a persisted first post.');
            }

            $newThreadId = ThreadId::generate();
            $this->requireOneAffected($database->execute(new CompiledQuery(
                'INSERT INTO `forwext_threads` '
                . '(`thread_id`, `forum_node_id`, `author_user_id`, `type_key`, `title`, `moderation_state`, '
                . '`locked`, `sticky`, `featured`, `version`, `created_at_utc`, `updated_at_utc`) '
                . 'VALUES (:thread_id, :forum_node_id, :author_user_id, :type_key, :title, :moderation_state, '
                . ':locked, 0, 0, 1, :created_at, :updated_at)',
                [
                    'thread_id' => $newThreadId->value(),
                    'forum_node_id' => $targetForumNodeId->value(),
                    'author_user_id' => $source['author_user_id'] ?? null,
                    'type_key' => (string) $source['type_key'],
                    'title' => (string) $source['title'],
                    'moderation_state' => (string) $source['moderation_state'],
                    'locked' => (bool) $source['locked'],
                    'created_at' => self::format($context->occurredAt),
                    'updated_at' => self::format($context->occurredAt),
                ],
            )), 'Thread copy target could not be created.');

            foreach ($posts as $post) {
                $copyPostId = PostId::generate();
                $this->requireOneAffected($database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_posts` '
                    . '(`post_id`, `thread_id`, `author_user_id`, `position`, `body_source`, `moderation_state`, '
                    . '`deleted`, `deleted_at_utc`, `version`, `created_at_utc`, `updated_at_utc`) '
                    . 'VALUES (:post_id, :thread_id, :author_user_id, :position, :body_source, :moderation_state, '
                    . ':deleted, :deleted_at, 1, :created_at, :updated_at)',
                    [
                        'post_id' => $copyPostId->value(),
                        'thread_id' => $newThreadId->value(),
                        'author_user_id' => $post['author_user_id'] ?? null,
                        'position' => (int) $post['position'],
                        'body_source' => (string) $post['body_source'],
                        'moderation_state' => (string) $post['moderation_state'],
                        'deleted' => (bool) $post['deleted'],
                        'deleted_at' => $post['deleted_at_utc'] ?? null,
                        'created_at' => (string) $post['created_at_utc'],
                        'updated_at' => (string) $post['updated_at_utc'],
                    ],
                )), 'Copied post could not be persisted.');
            }

            $sourceForum = ForumNodeId::fromStored((string) $source['forum_node_id']);
            $this->appendAudit(
                $context,
                ModerationAuditAction::ThreadCopy,
                'thread',
                $threadId->value(),
                $sourceForum,
                ['forum_node_id' => $sourceForum->value(), 'post_count' => count($posts)],
                [
                    'copied_thread_id' => $newThreadId->value(),
                    'target_forum_node_id' => $targetForumNodeId->value(),
                    'copied_post_count' => count($posts),
                ],
            );
            $this->appendAudit(
                $context,
                ModerationAuditAction::ThreadCopy,
                'thread',
                $newThreadId->value(),
                $targetForumNodeId,
                [],
                ['source_thread_id' => $threadId->value(), 'copied_post_count' => count($posts)],
            );
            return $newThreadId;
        });
    }

    public function mergeThreads(EntityId $destinationThreadId, array $sourceThreadIds, ModerationAuditContext $context): void
    {
        ThreadId::assert($destinationThreadId);
        $sources = array_values(array_filter(
            $this->normalizeThreadIds($sourceThreadIds, 20),
            static fn (EntityId $id): bool => !$id->equals($destinationThreadId),
        ));
        if ($sources === []) {
            throw new InvalidArgumentException('Thread merge requires at least one distinct source thread.');
        }

        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $destinationThreadId,
            $sources,
            $context,
        ): void {
            $lockIds = [$destinationThreadId, ...$sources];
            usort($lockIds, static fn (EntityId $a, EntityId $b): int => strcmp($a->value(), $b->value()));
            $rows = [];
            foreach ($lockIds as $id) {
                $rows[$id->value()] = $this->requireActiveThread($database, $id);
            }

            $destination = $rows[$destinationThreadId->value()];
            $destinationForum = ForumNodeId::fromStored((string) $destination['forum_node_id']);
            $nextPosition = (int) $database->fetchValue(new CompiledQuery(
                'SELECT COALESCE(MAX(`position`), 0) FROM `forwext_posts` WHERE `thread_id` = :thread_id',
                ['thread_id' => $destinationThreadId->value()],
                true,
            ));
            if ($nextPosition < 1) {
                throw new RuntimeException('Merge destination is missing its first post.');
            }

            $mergedIds = [];
            $appendedPosts = 0;
            foreach ($sources as $sourceId) {
                $source = $rows[$sourceId->value()];
                $sourceForum = ForumNodeId::fromStored((string) $source['forum_node_id']);
                $posts = $database->fetchAll(new CompiledQuery(
                    'SELECT `post_id`, `position` FROM `forwext_posts` '
                    . 'WHERE `thread_id` = :thread_id ORDER BY `position` ASC FOR UPDATE',
                    ['thread_id' => $sourceId->value()],
                    true,
                ));
                if ($posts === []) {
                    throw new RuntimeException('Merge source is missing posts.');
                }

                foreach ($posts as $post) {
                    ++$nextPosition;
                    ++$appendedPosts;
                    if ($nextPosition > 4294967295) {
                        throw new RuntimeException('Merge would exceed the post-position limit.');
                    }
                    $this->requireOneAffected($database->execute(new CompiledQuery(
                        'UPDATE `forwext_posts` SET `thread_id` = :destination_thread_id, '
                        . '`position` = :position, `version` = `version` + 1, `updated_at_utc` = :updated_at '
                        . 'WHERE `post_id` = :post_id',
                        [
                            'destination_thread_id' => $destinationThreadId->value(),
                            'position' => $nextPosition,
                            'updated_at' => self::format($context->occurredAt),
                            'post_id' => (string) $post['post_id'],
                        ],
                    )), 'Merged post could not be moved.');
                }

                $this->requireOneAffected($database->execute(new CompiledQuery(
                    'UPDATE `forwext_threads` SET `deleted` = 1, `deleted_at_utc` = :deleted_at, '
                    . '`merged_into_thread_id` = :destination_thread_id, `version` = `version` + 1, '
                    . '`updated_at_utc` = :updated_at WHERE `thread_id` = :thread_id',
                    [
                        'deleted_at' => self::format($context->occurredAt),
                        'destination_thread_id' => $destinationThreadId->value(),
                        'updated_at' => self::format($context->occurredAt),
                        'thread_id' => $sourceId->value(),
                    ],
                )), 'Merge source tombstone could not be persisted.');
                $mergedIds[] = $sourceId->value();
                $this->appendAudit(
                    $context,
                    ModerationAuditAction::ThreadMerge,
                    'thread',
                    $sourceId->value(),
                    $sourceForum,
                    ['forum_node_id' => $sourceForum->value(), 'post_count' => count($posts)],
                    ['merged_into_thread_id' => $destinationThreadId->value(), 'moved_post_count' => count($posts)],
                );
            }

            $this->updateThreadFields($database, $destinationThreadId, [], [], $context->occurredAt);
            $this->appendAudit(
                $context,
                ModerationAuditAction::ThreadMerge,
                'thread',
                $destinationThreadId->value(),
                $destinationForum,
                [],
                ['merged_source_thread_ids' => $mergedIds, 'appended_post_count' => $appendedPosts],
            );
        });
    }

    public function splitThread(
        EntityId $sourceThreadId,
        array $postIds,
        EntityId $targetForumNodeId,
        ThreadTitle $newTitle,
        ModerationAuditContext $context,
    ): EntityId {
        ThreadId::assert($sourceThreadId);
        ForumNodeId::assert($targetForumNodeId);
        $selectedIds = $this->normalizePostIds($postIds, 500);
        if ($selectedIds === []) {
            throw new InvalidArgumentException('Thread split requires at least one selected post.');
        }

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $sourceThreadId,
            $selectedIds,
            $targetForumNodeId,
            $newTitle,
            $context,
        ): EntityId {
            $source = $this->requireActiveThread($database, $sourceThreadId);
            $rows = $database->fetchAll(new CompiledQuery(
                'SELECT `post_id`, `author_user_id`, `position`, `moderation_state`, `deleted` '
                . 'FROM `forwext_posts` WHERE `thread_id` = :thread_id ORDER BY `position` ASC FOR UPDATE',
                ['thread_id' => $sourceThreadId->value()],
                true,
            ));
            if (count($rows) < 2) {
                throw new RuntimeException('Thread split requires at least one reply beyond the first post.');
            }

            $selectedMap = [];
            foreach ($selectedIds as $id) {
                $selectedMap[$id->value()] = true;
            }
            $selected = [];
            $remaining = [];
            foreach ($rows as $row) {
                $postId = (string) $row['post_id'];
                if (!isset($selectedMap[$postId])) {
                    $remaining[] = $row;
                    continue;
                }
                if ((int) $row['position'] === 1) {
                    throw new RuntimeException('The source first post cannot be split away from its thread.');
                }
                if ((bool) $row['deleted']) {
                    throw new RuntimeException('Deleted posts must be restored before they can be split.');
                }
                $selected[] = $row;
                unset($selectedMap[$postId]);
            }
            if ($selectedMap !== []) {
                throw new RuntimeException('Split selection contains posts outside the source thread.');
            }
            if ($selected === [] || $remaining === [] || (int) $remaining[0]['position'] !== 1) {
                throw new RuntimeException('Thread split would violate the source first-post invariant.');
            }

            $readRows = $database->fetchAll(new CompiledQuery(
                'SELECT `user_id`, `last_read_post_position` FROM `forwext_thread_read_state` '
                . 'WHERE `thread_id` = :thread_id FOR UPDATE',
                ['thread_id' => $sourceThreadId->value()],
                true,
            ));
            foreach ($readRows as $read) {
                $oldWatermark = (int) $read['last_read_post_position'];
                $newWatermark = 0;
                foreach ($remaining as $row) {
                    if ((int) $row['position'] <= $oldWatermark) {
                        ++$newWatermark;
                    }
                }
                if ($newWatermark === $oldWatermark) {
                    continue;
                }
                $this->requireOneAffected($database->execute(new CompiledQuery(
                    'UPDATE `forwext_thread_read_state` SET `last_read_post_position` = :position, '
                    . '`last_read_at_utc` = :updated_at '
                    . 'WHERE `user_id` = :user_id AND `thread_id` = :thread_id',
                    [
                        'position' => $newWatermark,
                        'updated_at' => self::format($context->occurredAt),
                        'user_id' => (string) $read['user_id'],
                        'thread_id' => $sourceThreadId->value(),
                    ],
                )), 'Thread read watermark could not be remapped after split.');
            }

            $newThreadId = ThreadId::generate();
            $firstSelected = $selected[0];
            $this->requireOneAffected($database->execute(new CompiledQuery(
                'INSERT INTO `forwext_threads` '
                . '(`thread_id`, `forum_node_id`, `author_user_id`, `type_key`, `title`, `moderation_state`, '
                . '`locked`, `sticky`, `featured`, `version`, `created_at_utc`, `updated_at_utc`) '
                . 'VALUES (:thread_id, :forum_node_id, :author_user_id, :type_key, :title, :moderation_state, '
                . '0, 0, 0, 1, :created_at, :updated_at)',
                [
                    'thread_id' => $newThreadId->value(),
                    'forum_node_id' => $targetForumNodeId->value(),
                    'author_user_id' => $firstSelected['author_user_id'] ?? null,
                    'type_key' => (string) $source['type_key'],
                    'title' => $newTitle->value(),
                    'moderation_state' => (string) $source['moderation_state'],
                    'created_at' => self::format($context->occurredAt),
                    'updated_at' => self::format($context->occurredAt),
                ],
            )), 'Split destination thread could not be created.');

            foreach ($selected as $index => $row) {
                $this->requireOneAffected($database->execute(new CompiledQuery(
                    'UPDATE `forwext_posts` SET `thread_id` = :thread_id, `position` = :position, '
                    . '`version` = `version` + 1, `updated_at_utc` = :updated_at WHERE `post_id` = :post_id',
                    [
                        'thread_id' => $newThreadId->value(),
                        'position' => $index + 1,
                        'updated_at' => self::format($context->occurredAt),
                        'post_id' => (string) $row['post_id'],
                    ],
                )), 'Split post could not be moved.');
            }
            foreach ($remaining as $index => $row) {
                $newPosition = $index + 1;
                if ((int) $row['position'] === $newPosition) {
                    continue;
                }
                $this->requireOneAffected($database->execute(new CompiledQuery(
                    'UPDATE `forwext_posts` SET `position` = :position, `version` = `version` + 1, '
                    . '`updated_at_utc` = :updated_at WHERE `post_id` = :post_id',
                    [
                        'position' => $newPosition,
                        'updated_at' => self::format($context->occurredAt),
                        'post_id' => (string) $row['post_id'],
                    ],
                )), 'Source post positions could not be compacted after split.');
            }

            $this->updateThreadFields($database, $sourceThreadId, [], [], $context->occurredAt);
            $sourceForum = ForumNodeId::fromStored((string) $source['forum_node_id']);
            $this->appendAudit(
                $context,
                ModerationAuditAction::ThreadSplit,
                'thread',
                $sourceThreadId->value(),
                $sourceForum,
                ['post_count' => count($rows)],
                [
                    'new_thread_id' => $newThreadId->value(),
                    'target_forum_node_id' => $targetForumNodeId->value(),
                    'moved_post_count' => count($selected),
                    'remaining_post_count' => count($remaining),
                ],
            );
            $this->appendAudit(
                $context,
                ModerationAuditAction::ThreadSplit,
                'thread',
                $newThreadId->value(),
                $targetForumNodeId,
                [],
                ['source_thread_id' => $sourceThreadId->value(), 'post_count' => count($selected)],
            );
            return $newThreadId;
        });
    }

    public function setThreadLocked(EntityId $threadId, bool $locked, ModerationAuditContext $context): void
    {
        $this->mutateThreadFlag(
            $threadId,
            'locked',
            $locked,
            $context,
            $locked ? ModerationAuditAction::ThreadLock : ModerationAuditAction::ThreadUnlock,
        );
    }

    public function setThreadSticky(EntityId $threadId, bool $sticky, ModerationAuditContext $context): void
    {
        $this->mutateThreadFlag(
            $threadId,
            'sticky',
            $sticky,
            $context,
            $sticky ? ModerationAuditAction::ThreadSticky : ModerationAuditAction::ThreadUnsticky,
        );
    }

    public function approveThread(EntityId $threadId, ModerationAuditContext $context): void
    {
        ThreadId::assert($threadId);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($threadId, $context): void {
            $row = $this->requireActiveThread($database, $threadId);
            if ((string) $row['moderation_state'] === ThreadModerationState::Visible->value) {
                return;
            }
            $forum = ForumNodeId::fromStored((string) $row['forum_node_id']);
            $before = (string) $row['moderation_state'];
            $this->updateThreadFields(
                $database,
                $threadId,
                ['`moderation_state` = :state'],
                ['state' => ThreadModerationState::Visible->value],
                $context->occurredAt,
            );
            $this->appendAudit(
                $context,
                ModerationAuditAction::ThreadApprove,
                'thread',
                $threadId->value(),
                $forum,
                ['moderation_state' => $before],
                ['moderation_state' => ThreadModerationState::Visible->value],
            );
        });
    }

    public function setThreadDeleted(EntityId $threadId, bool $deleted, ModerationAuditContext $context): void
    {
        ThreadId::assert($threadId);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($threadId, $deleted, $context): void {
            $row = $this->requireThread($database, $threadId);
            if (($row['merged_into_thread_id'] ?? null) !== null && !$deleted) {
                throw new RuntimeException('Merged source threads cannot be restored as independent threads.');
            }
            if ((bool) $row['deleted'] === $deleted) {
                return;
            }
            $forum = ForumNodeId::fromStored((string) $row['forum_node_id']);
            $this->requireOneAffected($database->execute(new CompiledQuery(
                'UPDATE `forwext_threads` SET `deleted` = :deleted, `deleted_at_utc` = :deleted_at, '
                . '`version` = `version` + 1, `updated_at_utc` = :updated_at WHERE `thread_id` = :thread_id',
                [
                    'deleted' => $deleted,
                    'deleted_at' => $deleted ? self::format($context->occurredAt) : null,
                    'updated_at' => self::format($context->occurredAt),
                    'thread_id' => $threadId->value(),
                ],
            )), 'Thread delete/restore state could not be persisted.');
            $this->appendAudit(
                $context,
                $deleted ? ModerationAuditAction::ThreadDelete : ModerationAuditAction::ThreadRestore,
                'thread',
                $threadId->value(),
                $forum,
                ['deleted' => !$deleted],
                ['deleted' => $deleted],
            );
        });
    }

    public function approvePost(EntityId $postId, ModerationAuditContext $context): void
    {
        PostId::assert($postId);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($postId, $context): void {
            $row = $this->requirePostContext($database, $postId);
            if ((bool) $row['deleted']) {
                throw new RuntimeException('Deleted posts must be restored before approval.');
            }
            if ((string) $row['moderation_state'] === PostModerationState::Visible->value) {
                return;
            }
            $this->snapshotPost($database, $row, 'post.approved', $context);
            $this->requireOneAffected($database->execute(new CompiledQuery(
                'UPDATE `forwext_posts` SET `moderation_state` = :state, `version` = `version` + 1, '
                . '`updated_at_utc` = :updated_at WHERE `post_id` = :post_id',
                [
                    'state' => PostModerationState::Visible->value,
                    'updated_at' => self::format($context->occurredAt),
                    'post_id' => $postId->value(),
                ],
            )), 'Post approval state could not be persisted.');
            $forum = ForumNodeId::fromStored((string) $row['forum_node_id']);
            $this->appendAudit(
                $context,
                ModerationAuditAction::PostApprove,
                'post',
                $postId->value(),
                $forum,
                ['moderation_state' => (string) $row['moderation_state']],
                ['moderation_state' => PostModerationState::Visible->value],
            );
        });
    }

    public function setPostDeleted(EntityId $postId, bool $deleted, ModerationAuditContext $context): void
    {
        PostId::assert($postId);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($postId, $deleted, $context): void {
            $row = $this->requirePostContext($database, $postId);
            if ($deleted && (int) $row['position'] === 1) {
                throw new RuntimeException('A first post must be moderated through the owning thread delete operation.');
            }
            if ((bool) $row['deleted'] === $deleted) {
                return;
            }
            $this->snapshotPost($database, $row, $deleted ? 'post.deleted' : 'post.restored', $context);
            $this->requireOneAffected($database->execute(new CompiledQuery(
                'UPDATE `forwext_posts` SET `deleted` = :deleted, `deleted_at_utc` = :deleted_at, '
                . '`version` = `version` + 1, `updated_at_utc` = :updated_at WHERE `post_id` = :post_id',
                [
                    'deleted' => $deleted,
                    'deleted_at' => $deleted ? self::format($context->occurredAt) : null,
                    'updated_at' => self::format($context->occurredAt),
                    'post_id' => $postId->value(),
                ],
            )), 'Post delete/restore state could not be persisted.');
            $forum = ForumNodeId::fromStored((string) $row['forum_node_id']);
            $this->appendAudit(
                $context,
                $deleted ? ModerationAuditAction::PostDelete : ModerationAuditAction::PostRestore,
                'post',
                $postId->value(),
                $forum,
                ['deleted' => !$deleted],
                ['deleted' => $deleted],
            );
        });
    }

    public function bulkThreads(BulkThreadAction $action, array $threadIds, ModerationAuditContext $context): void
    {
        $ids = $this->normalizeThreadIds($threadIds, 100);
        if ($ids === []) {
            throw new InvalidArgumentException('Bulk thread moderation requires at least one thread.');
        }
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($action, $ids, $context): void {
            $lockIds = $ids;
            usort($lockIds, static fn (EntityId $a, EntityId $b): int => strcmp($a->value(), $b->value()));
            $rows = [];
            foreach ($lockIds as $id) {
                $rows[$id->value()] = $this->requireThread($database, $id);
            }
            foreach ($ids as $id) {
                $this->applyBulkThreadRow($database, $action, $id, $rows[$id->value()], $context);
            }
            $this->appendAudit(
                $context,
                ModerationAuditAction::BulkThread,
                'bulk.thread',
                $context->requestId->value(),
                null,
                ['target_count' => count($ids)],
                [
                    'action' => $action->value,
                    'thread_ids' => array_map(static fn (EntityId $id): string => $id->value(), $ids),
                ],
            );
        });
    }

    public function bulkPosts(BulkPostAction $action, array $postIds, ModerationAuditContext $context): void
    {
        $ids = $this->normalizePostIds($postIds, 100);
        if ($ids === []) {
            throw new InvalidArgumentException('Bulk post moderation requires at least one post.');
        }
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($action, $ids, $context): void {
            $lockIds = $ids;
            usort($lockIds, static fn (EntityId $a, EntityId $b): int => strcmp($a->value(), $b->value()));
            $rows = [];
            foreach ($lockIds as $id) {
                $rows[$id->value()] = $this->requirePostContext($database, $id);
            }
            foreach ($ids as $id) {
                $this->applyBulkPostRow($database, $action, $id, $rows[$id->value()], $context);
            }
            $this->appendAudit(
                $context,
                ModerationAuditAction::BulkPost,
                'bulk.post',
                $context->requestId->value(),
                null,
                ['target_count' => count($ids)],
                [
                    'action' => $action->value,
                    'post_ids' => array_map(static fn (EntityId $id): string => $id->value(), $ids),
                ],
            );
        });
    }

    private function mutateThreadFlag(
        EntityId $threadId,
        string $column,
        bool $value,
        ModerationAuditContext $context,
        ModerationAuditAction $action,
    ): void {
        ThreadId::assert($threadId);
        if (!in_array($column, ['locked', 'sticky'], true)) {
            throw new RuntimeException('Unsupported moderated thread flag.');
        }
        $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $threadId,
            $column,
            $value,
            $context,
            $action,
        ): void {
            $row = $this->requireActiveThread($database, $threadId);
            if ((bool) $row[$column] === $value) {
                return;
            }
            $forum = ForumNodeId::fromStored((string) $row['forum_node_id']);
            $this->updateThreadFields(
                $database,
                $threadId,
                ['`' . $column . '` = :flag'],
                ['flag' => $value],
                $context->occurredAt,
            );
            $this->appendAudit($context, $action, 'thread', $threadId->value(), $forum, [$column => !$value], [$column => $value]);
        });
    }

    /** @param array<string,mixed> $row */
    private function applyBulkThreadRow(
        TransactionalQueryExecutor $database,
        BulkThreadAction $action,
        EntityId $threadId,
        array $row,
        ModerationAuditContext $context,
    ): void {
        $forum = ForumNodeId::fromStored((string) $row['forum_node_id']);
        if (($row['merged_into_thread_id'] ?? null) !== null) {
            throw new RuntimeException('Merged source threads cannot be bulk-moderated.');
        }
        if (
            in_array($action, [BulkThreadAction::Approve, BulkThreadAction::Reject], true)
            && (string) $row['moderation_state'] !== ThreadModerationState::Pending->value
        ) {
            throw new RuntimeException('Bulk approval actions require a pending thread.');
        }
        [$auditAction, $before, $after, $assignments, $parameters] = match ($action) {
            BulkThreadAction::Lock => [ModerationAuditAction::ThreadLock, ['locked' => (bool) $row['locked']], ['locked' => true], ['`locked` = :value'], ['value' => true]],
            BulkThreadAction::Unlock => [ModerationAuditAction::ThreadUnlock, ['locked' => (bool) $row['locked']], ['locked' => false], ['`locked` = :value'], ['value' => false]],
            BulkThreadAction::Sticky => [ModerationAuditAction::ThreadSticky, ['sticky' => (bool) $row['sticky']], ['sticky' => true], ['`sticky` = :value'], ['value' => true]],
            BulkThreadAction::Unsticky => [ModerationAuditAction::ThreadUnsticky, ['sticky' => (bool) $row['sticky']], ['sticky' => false], ['`sticky` = :value'], ['value' => false]],
            BulkThreadAction::Approve => [ModerationAuditAction::ThreadApprove, ['moderation_state' => (string) $row['moderation_state']], ['moderation_state' => ThreadModerationState::Visible->value], ['`moderation_state` = :value'], ['value' => ThreadModerationState::Visible->value]],
            BulkThreadAction::Reject => [ModerationAuditAction::ThreadReject, ['moderation_state' => (string) $row['moderation_state']], ['moderation_state' => ThreadModerationState::Rejected->value], ['`moderation_state` = :value'], ['value' => ThreadModerationState::Rejected->value]],
            BulkThreadAction::Delete => [ModerationAuditAction::ThreadDelete, ['deleted' => (bool) $row['deleted']], ['deleted' => true], ['`deleted` = 1', '`deleted_at_utc` = :deleted_at'], ['deleted_at' => self::format($context->occurredAt)]],
            BulkThreadAction::Restore => [ModerationAuditAction::ThreadRestore, ['deleted' => (bool) $row['deleted']], ['deleted' => false], ['`deleted` = 0', '`deleted_at_utc` = NULL'], []],
        };
        if ($before === $after) {
            return;
        }
        if ($action !== BulkThreadAction::Restore && (bool) $row['deleted']) {
            throw new RuntimeException('Deleted threads must be restored before other bulk actions.');
        }
        $this->updateThreadFields($database, $threadId, $assignments, $parameters, $context->occurredAt);
        $this->appendAudit($context, $auditAction, 'thread', $threadId->value(), $forum, $before, $after);
    }

    /** @param array<string,mixed> $row */
    private function applyBulkPostRow(
        TransactionalQueryExecutor $database,
        BulkPostAction $action,
        EntityId $postId,
        array $row,
        ModerationAuditContext $context,
    ): void {
        $forum = ForumNodeId::fromStored((string) $row['forum_node_id']);
        if ($action === BulkPostAction::Delete && (int) $row['position'] === 1) {
            throw new RuntimeException('First posts must be moderated through thread delete in bulk operations.');
        }
        if (
            in_array($action, [BulkPostAction::Approve, BulkPostAction::Reject], true)
            && (string) $row['moderation_state'] !== PostModerationState::Pending->value
        ) {
            throw new RuntimeException('Bulk approval actions require a pending post.');
        }
        if (in_array($action, [BulkPostAction::Approve, BulkPostAction::Reject], true) && (bool) $row['deleted']) {
            throw new RuntimeException('Deleted posts must be restored before bulk approval actions.');
        }
        [$auditAction, $historyAction, $before, $after, $assignments, $parameters] = match ($action) {
            BulkPostAction::Approve => [ModerationAuditAction::PostApprove, 'post.approved', ['moderation_state' => (string) $row['moderation_state']], ['moderation_state' => PostModerationState::Visible->value], ['`moderation_state` = :value'], ['value' => PostModerationState::Visible->value]],
            BulkPostAction::Reject => [ModerationAuditAction::PostReject, 'post.rejected', ['moderation_state' => (string) $row['moderation_state']], ['moderation_state' => PostModerationState::Rejected->value], ['`moderation_state` = :value'], ['value' => PostModerationState::Rejected->value]],
            BulkPostAction::Delete => [ModerationAuditAction::PostDelete, 'post.deleted', ['deleted' => (bool) $row['deleted']], ['deleted' => true], ['`deleted` = 1', '`deleted_at_utc` = :deleted_at'], ['deleted_at' => self::format($context->occurredAt)]],
            BulkPostAction::Restore => [ModerationAuditAction::PostRestore, 'post.restored', ['deleted' => (bool) $row['deleted']], ['deleted' => false], ['`deleted` = 0', '`deleted_at_utc` = NULL'], []],
        };
        if ($before === $after) {
            return;
        }
        $this->snapshotPost($database, $row, $historyAction, $context);
        $sql = 'UPDATE `forwext_posts` SET ' . implode(', ', $assignments)
            . ', `version` = `version` + 1, `updated_at_utc` = :updated_at WHERE `post_id` = :post_id';
        $parameters['updated_at'] = self::format($context->occurredAt);
        $parameters['post_id'] = $postId->value();
        $this->requireOneAffected($database->execute(new CompiledQuery($sql, $parameters)), 'Bulk post mutation failed.');
        $this->appendAudit($context, $auditAction, 'post', $postId->value(), $forum, $before, $after);
    }

    /** @param array<string,mixed> $row */
    private function snapshotPost(
        TransactionalQueryExecutor $database,
        array $row,
        string $action,
        ModerationAuditContext $context,
    ): void {
        $this->requireOneAffected($database->execute(new CompiledQuery(
            'INSERT INTO `forwext_post_history` '
            . '(`post_id`, `snapshot_version`, `body_source`, `moderation_state`, `deleted`, '
            . '`action`, `actor_user_id`, `changed_at_utc`) '
            . 'VALUES (:post_id, :snapshot_version, :body_source, :moderation_state, :deleted, '
            . ':action, :actor_user_id, :changed_at)',
            [
                'post_id' => (string) $row['post_id'],
                'snapshot_version' => (int) $row['version'],
                'body_source' => (string) $row['body_source'],
                'moderation_state' => (string) $row['moderation_state'],
                'deleted' => (bool) $row['deleted'],
                'action' => $action,
                'actor_user_id' => $context->actorUserId->value(),
                'changed_at' => self::format($context->occurredAt),
            ],
        )), 'Post history snapshot could not be persisted.');
    }

    /** @return array<string,mixed> */
    private function requireThread(TransactionalQueryExecutor $database, EntityId $threadId): array
    {
        $row = $database->fetchOne(new CompiledQuery(
            $this->threadSelectSql() . ' WHERE `thread_id` = :thread_id FOR UPDATE',
            ['thread_id' => $threadId->value()],
            true,
        ));
        if ($row === null) {
            throw new RuntimeException('Moderation thread is not available.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireActiveThread(TransactionalQueryExecutor $database, EntityId $threadId): array
    {
        $row = $this->requireThread($database, $threadId);
        if ((bool) $row['deleted'] || ($row['merged_into_thread_id'] ?? null) !== null) {
            throw new RuntimeException('Moderation operation requires an active thread.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function requirePostContext(TransactionalQueryExecutor $database, EntityId $postId): array
    {
        $row = $database->fetchOne(new CompiledQuery(
            $this->postContextSelectSql() . ' WHERE p.`post_id` = :post_id FOR UPDATE',
            ['post_id' => $postId->value()],
            true,
        ));
        if ($row === null) {
            throw new RuntimeException('Moderation post is not available.');
        }
        if ((bool) $row['thread_deleted'] || ($row['merged_into_thread_id'] ?? null) !== null) {
            throw new RuntimeException('Post parent thread is not active.');
        }
        return $row;
    }

    /** @param list<string> $assignments @param array<string,string|int|float|bool|null> $parameters */
    private function updateThreadFields(
        TransactionalQueryExecutor $database,
        EntityId $threadId,
        array $assignments,
        array $parameters,
        DateTimeImmutable $at,
    ): void {
        $assignments[] = '`version` = `version` + 1';
        $assignments[] = '`updated_at_utc` = :updated_at';
        $parameters['updated_at'] = self::format($at);
        $parameters['thread_id'] = $threadId->value();
        $this->requireOneAffected($database->execute(new CompiledQuery(
            'UPDATE `forwext_threads` SET ' . implode(', ', $assignments) . ' WHERE `thread_id` = :thread_id',
            $parameters,
        )), 'Thread moderation mutation did not affect exactly one row.');
    }

    /** @param array<string,bool|int|float|string|null|list<string>> $before @param array<string,bool|int|float|string|null|list<string>> $after */
    private function appendAudit(
        ModerationAuditContext $context,
        ModerationAuditAction $action,
        string $targetType,
        string $targetId,
        ?EntityId $forumNodeId,
        array $before,
        array $after,
    ): void {
        $this->audit->append(new ModerationAuditEvent(
            ModerationAuditEvent::generateId(),
            $context->actorUserId,
            $action,
            $targetType,
            $targetId,
            $forumNodeId,
            $context->reasonCode,
            $context->requestId,
            $before,
            $after,
            $context->occurredAt,
        ));
    }

    /** @param list<EntityId> $ids @return list<EntityId> */
    private function normalizeThreadIds(array $ids, int $max): array
    {
        if (count($ids) > $max) {
            throw new InvalidArgumentException('Thread moderation target count exceeds the operation limit.');
        }
        $unique = [];
        foreach ($ids as $id) {
            if (!$id instanceof EntityId) {
                throw new InvalidArgumentException('Thread moderation targets must be entity ids.');
            }
            ThreadId::assert($id);
            $unique[$id->value()] = $id;
        }
        return array_values($unique);
    }

    /** @param list<EntityId> $ids @return list<EntityId> */
    private function normalizePostIds(array $ids, int $max): array
    {
        if (count($ids) > $max) {
            throw new InvalidArgumentException('Post moderation target count exceeds the operation limit.');
        }
        $unique = [];
        foreach ($ids as $id) {
            if (!$id instanceof EntityId) {
                throw new InvalidArgumentException('Post moderation targets must be entity ids.');
            }
            PostId::assert($id);
            $unique[$id->value()] = $id;
        }
        return array_values($unique);
    }

    private function threadSelectSql(): string
    {
        return 'SELECT `thread_id`, `forum_node_id`, `author_user_id`, `type_key`, `title`, '
            . '`moderation_state`, `locked`, `sticky`, `featured`, `version`, `deleted`, '
            . '`deleted_at_utc`, `merged_into_thread_id`, `created_at_utc`, `updated_at_utc` '
            . 'FROM `forwext_threads`';
    }

    private function postContextSelectSql(): string
    {
        return 'SELECT p.`post_id`, p.`thread_id`, t.`forum_node_id`, p.`author_user_id`, p.`position`, '
            . 'p.`body_source`, p.`moderation_state`, p.`deleted`, p.`deleted_at_utc`, p.`version`, '
            . 't.`deleted` AS `thread_deleted`, t.`merged_into_thread_id` '
            . 'FROM `forwext_posts` p INNER JOIN `forwext_threads` t ON t.`thread_id` = p.`thread_id`';
    }

    /** @param array<string,mixed> $row */
    private function threadRecord(array $row): ModerationThreadRecord
    {
        return new ModerationThreadRecord(
            ThreadId::fromStored((string) $row['thread_id']),
            ForumNodeId::fromStored((string) $row['forum_node_id']),
            ThreadModerationState::from((string) $row['moderation_state']),
            (bool) $row['locked'],
            (bool) $row['sticky'],
            (bool) $row['deleted'],
            ($row['merged_into_thread_id'] ?? null) === null
                ? null
                : ThreadId::fromStored((string) $row['merged_into_thread_id']),
        );
    }

    /** @param array<string,mixed> $row */
    private function postRecord(array $row): ModerationPostRecord
    {
        return new ModerationPostRecord(
            PostId::fromStored((string) $row['post_id']),
            ThreadId::fromStored((string) $row['thread_id']),
            ForumNodeId::fromStored((string) $row['forum_node_id']),
            (int) $row['position'],
            PostModerationState::from((string) $row['moderation_state']),
            (bool) $row['deleted'],
        );
    }

    private function requireOneAffected(int $affected, string $message): void
    {
        if ($affected !== 1) {
            throw new RuntimeException($message);
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
