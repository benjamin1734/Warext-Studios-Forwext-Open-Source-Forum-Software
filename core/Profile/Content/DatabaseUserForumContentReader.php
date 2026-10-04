<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Content;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Node\ForumNodeId;
use Forwext\Core\Forum\Post\PostId;
use Forwext\Core\Forum\Thread\ThreadId;
use Forwext\Core\Search\Access\SearchAccessScopeProvider;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseUserForumContentReader implements UserForumContentReader
{
    private const FORUM_SCOPE_PREFIX = 'forum.node:';

    public function __construct(
        private QueryExecutor $database,
        private SearchAccessScopeProvider $forumScopes,
    ) {
    }

    public function threads(EntityId $viewerUserId, EntityId $targetUserId, int $limit = 20, int $offset = 0): array
    {
        UserId::assert($viewerUserId);
        UserId::assert($targetUserId);
        self::assertPagination($limit, $offset);
        [$forumSql, $parameters] = $this->forumScopeSql($viewerUserId);
        if ($forumSql === '') {
            return [];
        }
        $parameters['target_user_id'] = $targetUserId->value();

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT t.`thread_id`, t.`forum_node_id`, t.`title` AS `thread_title`, '
            . 'n.`title` AS `forum_title`, t.`created_at_utc`, t.`updated_at_utc` '
            . 'FROM `forwext_threads` t '
            . 'INNER JOIN `forwext_nodes` n ON n.`node_id` = t.`forum_node_id` '
            . 'WHERE t.`author_user_id` = :target_user_id '
            . "AND t.`moderation_state` = 'visible' "
            . 'AND t.`deleted` = 0 AND t.`archived` = 0 AND t.`merged_into_thread_id` IS NULL '
            . 'AND t.`forum_node_id` IN (' . $forumSql . ') '
            . 'ORDER BY t.`updated_at_utc` DESC, t.`thread_id` DESC '
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset,
            $parameters,
        ));

        return array_map(
            static fn (array $row): UserForumContentItem => new UserForumContentItem(
                UserForumContentType::Thread,
                ThreadId::fromStored((string) $row['thread_id']),
                ThreadId::fromStored((string) $row['thread_id']),
                ForumNodeId::fromStored((string) $row['forum_node_id']),
                (string) $row['forum_title'],
                (string) $row['thread_title'],
                '',
                null,
                self::parse((string) $row['created_at_utc']),
                self::parse((string) $row['updated_at_utc']),
            ),
            $rows,
        );
    }

    public function posts(EntityId $viewerUserId, EntityId $targetUserId, int $limit = 20, int $offset = 0): array
    {
        UserId::assert($viewerUserId);
        UserId::assert($targetUserId);
        self::assertPagination($limit, $offset);
        [$forumSql, $parameters] = $this->forumScopeSql($viewerUserId);
        if ($forumSql === '') {
            return [];
        }
        $parameters['target_user_id'] = $targetUserId->value();

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT p.`post_id`, p.`thread_id`, p.`position`, '
            . 'LEFT(p.`body_source`, 600) AS `excerpt`, '
            . 'p.`created_at_utc`, p.`updated_at_utc`, '
            . 't.`forum_node_id`, t.`title` AS `thread_title`, n.`title` AS `forum_title` '
            . 'FROM `forwext_posts` p '
            . 'INNER JOIN `forwext_threads` t ON t.`thread_id` = p.`thread_id` '
            . 'INNER JOIN `forwext_nodes` n ON n.`node_id` = t.`forum_node_id` '
            . 'WHERE p.`author_user_id` = :target_user_id '
            . "AND p.`moderation_state` = 'visible' AND p.`deleted` = 0 "
            . "AND t.`moderation_state` = 'visible' "
            . 'AND t.`deleted` = 0 AND t.`archived` = 0 AND t.`merged_into_thread_id` IS NULL '
            . 'AND t.`forum_node_id` IN (' . $forumSql . ') '
            . 'ORDER BY p.`updated_at_utc` DESC, p.`post_id` DESC '
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset,
            $parameters,
        ));

        return array_map(
            static fn (array $row): UserForumContentItem => new UserForumContentItem(
                UserForumContentType::Post,
                PostId::fromStored((string) $row['post_id']),
                ThreadId::fromStored((string) $row['thread_id']),
                ForumNodeId::fromStored((string) $row['forum_node_id']),
                (string) $row['forum_title'],
                (string) $row['thread_title'],
                (string) $row['excerpt'],
                (int) $row['position'],
                self::parse((string) $row['created_at_utc']),
                self::parse((string) $row['updated_at_utc']),
            ),
            $rows,
        );
    }

    /** @return array{0:string,1:array<string,string>} */
    private function forumScopeSql(EntityId $viewerUserId): array
    {
        $ids = [];
        foreach ($this->forumScopes->scopes($viewerUserId) as $scope) {
            if (!str_starts_with($scope, self::FORUM_SCOPE_PREFIX)) {
                continue;
            }
            $id = substr($scope, strlen(self::FORUM_SCOPE_PREFIX));
            if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                continue;
            }
            $ids[$id] = true;
        }
        $ids = array_keys($ids);
        sort($ids, SORT_STRING);
        if ($ids === []) {
            return ['', []];
        }

        $parameters = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $name = 'forum_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }

        return [implode(',', $placeholders), $parameters];
    }

    private static function assertPagination(int $limit, int $offset): void
    {
        if ($limit < 1 || $limit > 50 || $offset < 0 || $offset > 10000) {
            throw new InvalidArgumentException('User forum content pagination is invalid.');
        }
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored user forum content timestamp is invalid.');
        }
        return $parsed;
    }
}
