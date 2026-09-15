<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Thread\ThreadId;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabasePostRepository implements PostRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function find(EntityId $postId): ?Post
    {
        PostId::assert($postId);
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectSql() . ' WHERE `post_id` = :post_id LIMIT 1',
            ['post_id' => $postId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function firstPost(EntityId $threadId): ?Post
    {
        ThreadId::assert($threadId);
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectSql() . ' WHERE `thread_id` = :thread_id AND `position` = 1 LIMIT 1',
            ['thread_id' => $threadId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function create(
        EntityId $threadId,
        EntityId $authorUserId,
        PostBody $body,
        bool $requiresApproval,
        bool $mustBeFirst,
        DateTimeImmutable $now,
    ): Post {
        ThreadId::assert($threadId);
        UserId::assert($authorUserId);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $threadId, $authorUserId, $body, $requiresApproval, $mustBeFirst, $now,
        ): Post {
            $threadRow = $database->fetchOne(new CompiledQuery(
                'SELECT `thread_id` FROM `forwext_threads` WHERE `thread_id` = :thread_id FOR UPDATE',
                ['thread_id' => $threadId->value()],
                true,
            ));
            if ($threadRow === null) {
                throw new PostOperationException('Post thread is not available.');
            }

            $maxPosition = (int) $database->fetchValue(new CompiledQuery(
                'SELECT COALESCE(MAX(`position`), 0) FROM `forwext_posts` WHERE `thread_id` = :thread_id',
                ['thread_id' => $threadId->value()],
                true,
            ));
            if ($mustBeFirst && $maxPosition !== 0) {
                throw new PostOperationException('Thread already has a first post.');
            }
            if (!$mustBeFirst && $maxPosition === 0) {
                throw new PostOperationException('Thread first post must exist before replies.');
            }
            if ($maxPosition >= 4294967295) {
                throw new PostOperationException('Thread post position limit has been reached.');
            }

            $post = Post::create(
                PostId::generate(), $threadId, $authorUserId, $maxPosition + 1, $body, $requiresApproval, $now,
            );
            $affected = $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_posts` '
                . '(`post_id`, `thread_id`, `author_user_id`, `position`, `body_source`, '
                . '`moderation_state`, `deleted`, `deleted_at_utc`, `version`, `created_at_utc`, `updated_at_utc`) '
                . 'VALUES (:post_id, :thread_id, :author_user_id, :position, :body_source, '
                . ':moderation_state, 0, NULL, 1, :created_at, :updated_at)',
                [
                    'post_id' => $post->id()->value(), 'thread_id' => $threadId->value(),
                    'author_user_id' => $authorUserId->value(), 'position' => $post->position(),
                    'body_source' => $body->source(), 'moderation_state' => $post->moderationState()->value,
                    'created_at' => self::format($post->createdAt()), 'updated_at' => self::format($post->updatedAt()),
                ],
            ));
            if ($affected !== 1) {
                throw new PostConcurrencyException('Post creation failed because its position was claimed concurrently.');
            }
            $post->markPersisted(1);
            return $post;
        });
    }

    public function save(Post $post): void
    {
        $newVersion = $this->database->transaction(function (TransactionalQueryExecutor $database) use ($post): int {
            $expectedVersion = $post->version();
            $newVersion = $expectedVersion + 1;
            $affected = $database->execute(new CompiledQuery(
                'UPDATE `forwext_posts` SET `body_source` = :body_source, '
                . '`moderation_state` = :moderation_state, `deleted` = :deleted, '
                . '`deleted_at_utc` = :deleted_at, `version` = :version, `updated_at_utc` = :updated_at '
                . 'WHERE `post_id` = :post_id AND `version` = :expected_version',
                [
                    'body_source' => $post->body()->source(), 'moderation_state' => $post->moderationState()->value,
                    'deleted' => $post->isDeleted(),
                    'deleted_at' => $post->deletedAt() === null ? null : self::format($post->deletedAt()),
                    'version' => $newVersion, 'updated_at' => self::format($post->updatedAt()),
                    'post_id' => $post->id()->value(), 'expected_version' => $expectedVersion,
                ],
            ));
            if ($affected !== 1) {
                throw new PostConcurrencyException('Post persistence failed because the stored version changed.');
            }

            foreach ($post->pendingHistory() as $entry) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_post_history` '
                    . '(`post_id`, `snapshot_version`, `body_source`, `moderation_state`, `deleted`, '
                    . '`action`, `actor_user_id`, `changed_at_utc`) '
                    . 'VALUES (:post_id, :snapshot_version, :body_source, :moderation_state, :deleted, '
                    . ':action, :actor_user_id, :changed_at)',
                    [
                        'post_id' => $post->id()->value(), 'snapshot_version' => $entry->snapshotVersion,
                        'body_source' => $entry->body->source(), 'moderation_state' => $entry->moderationState->value,
                        'deleted' => $entry->deleted, 'action' => $entry->action,
                        'actor_user_id' => $entry->actorUserId?->value(), 'changed_at' => self::format($entry->changedAt),
                    ],
                ));
            }
            return $newVersion;
        });
        $post->markPersisted($newVersion);
    }

    public function history(EntityId $postId, int $limit = 100, int $offset = 0): array
    {
        PostId::assert($postId);
        if ($limit < 1 || $limit > 500 || $offset < 0 || $offset > 1_000_000) {
            throw new InvalidArgumentException('Post history pagination is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `snapshot_version`, `body_source`, `moderation_state`, `deleted`, `action`, '
            . '`actor_user_id`, `changed_at_utc` FROM `forwext_post_history` '
            . 'WHERE `post_id` = :post_id ORDER BY `history_id` DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['post_id' => $postId->value()],
        ));
        return array_map(function (array $row): PostHistoryEntry {
            return new PostHistoryEntry(
                (int) $row['snapshot_version'], PostBody::fromString((string) $row['body_source']),
                PostModerationState::from((string) $row['moderation_state']), (bool) $row['deleted'],
                (string) $row['action'],
                ($row['actor_user_id'] ?? null) === null ? null : UserId::fromStored((string) $row['actor_user_id']),
                self::parse((string) $row['changed_at_utc']),
            );
        }, $rows);
    }

    public function pageByThread(
        EntityId $threadId,
        int $page = 1,
        int $perPage = 20,
        bool $includeDeleted = false,
        bool $includeNonVisible = false,
    ): PostPage {
        ThreadId::assert($threadId);
        if ($page < 1 || $page > 1_000_000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Post pagination is invalid.');
        }
        $offset = ($page - 1) * $perPage;
        if ($offset > 100_000_000) {
            throw new InvalidArgumentException('Post pagination offset is too large.');
        }
        [$where, $parameters] = $this->visibilityWhere($threadId, $includeDeleted, $includeNonVisible);
        $total = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_posts` WHERE ' . $where,
            $parameters,
        ));
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->selectSql() . ' WHERE ' . $where . ' ORDER BY `position` ASC '
            . 'LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $parameters,
        ));
        return new PostPage(array_map($this->hydrate(...), $rows), $page, $perPage, $total);
    }

    public function counters(EntityId $threadId): PostCounters
    {
        ThreadId::assert($threadId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT SUM(CASE WHEN `deleted` = 0 THEN 1 ELSE 0 END) AS `active_count`, '
            . "SUM(CASE WHEN `deleted` = 0 AND `moderation_state` = 'visible' THEN 1 ELSE 0 END) AS `visible_count` "
            . 'FROM `forwext_posts` WHERE `thread_id` = :thread_id',
            ['thread_id' => $threadId->value()],
        ));
        return new PostCounters((int) ($row['active_count'] ?? 0), (int) ($row['visible_count'] ?? 0));
    }

    /** @return array{0:string,1:array<string,string|int|float|bool|null>} */
    private function visibilityWhere(EntityId $threadId, bool $includeDeleted, bool $includeNonVisible): array
    {
        $where = '`thread_id` = :thread_id';
        if (!$includeDeleted) {
            $where .= ' AND `deleted` = 0';
        }
        if (!$includeNonVisible) {
            $where .= " AND `moderation_state` = 'visible'";
        }
        return [$where, ['thread_id' => $threadId->value()]];
    }

    private function selectSql(): string
    {
        return 'SELECT `post_id`, `thread_id`, `author_user_id`, `position`, `body_source`, '
            . '`moderation_state`, `deleted`, `deleted_at_utc`, `version`, `created_at_utc`, `updated_at_utc` '
            . 'FROM `forwext_posts`';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Post
    {
        foreach (['post_id', 'thread_id', 'position', 'body_source', 'moderation_state', 'deleted', 'version', 'created_at_utc', 'updated_at_utc'] as $required) {
            if (!array_key_exists($required, $row)) {
                throw new RuntimeException('Stored post row is missing required data.');
            }
        }
        return Post::hydrate(
            PostId::fromStored((string) $row['post_id']), ThreadId::fromStored((string) $row['thread_id']),
            ($row['author_user_id'] ?? null) === null ? null : UserId::fromStored((string) $row['author_user_id']),
            (int) $row['position'], PostBody::fromString((string) $row['body_source']),
            PostModerationState::from((string) $row['moderation_state']), (bool) $row['deleted'],
            ($row['deleted_at_utc'] ?? null) === null ? null : self::parse((string) $row['deleted_at_utc']),
            self::parse((string) $row['created_at_utc']), self::parse((string) $row['updated_at_utc']), (int) $row['version'],
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored post timestamp is invalid.');
        }
        return $parsed;
    }
}
