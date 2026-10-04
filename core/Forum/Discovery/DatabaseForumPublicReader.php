<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Discovery;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class DatabaseForumPublicReader
{
    public function __construct(private QueryExecutor $database)
    {
    }

    /**
     * @param list<EntityId> $forumNodeIds
     * @return array<string,array{
     *   thread_count:int,
     *   post_count:int,
     *   latest_thread_id:?string,
     *   latest_thread_title:?string,
     *   latest_username:?string,
     *   latest_at:?string
     * }>
     */
    public function forumSummaries(array $forumNodeIds): array
    {
        if ($forumNodeIds === []) {
            return [];
        }

        [$in, $parameters] = $this->forumIn($forumNodeIds, 'forum');
        $summaries = [];
        foreach ($forumNodeIds as $forumNodeId) {
            $summaries[$forumNodeId->value()] = [
                'thread_count' => 0,
                'post_count' => 0,
                'latest_thread_id' => null,
                'latest_thread_title' => null,
                'latest_username' => null,
                'latest_at' => null,
            ];
        }

        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT t.forum_node_id, COUNT(DISTINCT t.thread_id) AS thread_count, '
            . 'COUNT(p.post_id) AS post_count '
            . 'FROM forwext_threads t '
            . 'LEFT JOIN forwext_posts p ON p.thread_id=t.thread_id '
            . "AND p.deleted=0 AND p.moderation_state='visible' "
            . 'WHERE t.forum_node_id IN (' . $in . ') '
            . "AND t.deleted=0 AND t.archived=0 AND t.merged_into_thread_id IS NULL "
            . "AND t.moderation_state='visible' "
            . 'GROUP BY t.forum_node_id',
            $parameters,
        )) as $row) {
            $id = (string) ($row['forum_node_id'] ?? '');
            if (isset($summaries[$id])) {
                $summaries[$id]['thread_count'] = max(0, (int) ($row['thread_count'] ?? 0));
                $summaries[$id]['post_count'] = max(0, (int) ($row['post_count'] ?? 0));
            }
        }

        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT ranked.forum_node_id,ranked.thread_id,ranked.title,ranked.updated_at_utc,u.username '
            . 'FROM ('
            . 'SELECT t.forum_node_id,t.thread_id,t.title,p.author_user_id,p.updated_at_utc,p.position,p.post_id,'
            . 'ROW_NUMBER() OVER (PARTITION BY t.forum_node_id '
            . 'ORDER BY p.updated_at_utc DESC,p.position DESC,p.post_id DESC) AS fx_row_number '
            . 'FROM forwext_threads t '
            . 'INNER JOIN forwext_posts p ON p.thread_id=t.thread_id '
            . "AND p.deleted=0 AND p.moderation_state='visible' "
            . 'WHERE t.forum_node_id IN (' . $in . ') '
            . "AND t.deleted=0 AND t.archived=0 AND t.merged_into_thread_id IS NULL "
            . "AND t.moderation_state='visible'"
            . ') ranked '
            . 'LEFT JOIN forwext_users u ON u.user_id=ranked.author_user_id '
            . 'WHERE ranked.fx_row_number=1',
            $parameters,
        )) as $row) {
            $id = (string) ($row['forum_node_id'] ?? '');
            if (!isset($summaries[$id])) {
                continue;
            }
            $summaries[$id]['latest_thread_id'] = self::nullableString($row['thread_id'] ?? null);
            $summaries[$id]['latest_thread_title'] = self::nullableString($row['title'] ?? null);
            $summaries[$id]['latest_username'] = self::nullableString($row['username'] ?? null);
            $summaries[$id]['latest_at'] = self::nullableString($row['updated_at_utc'] ?? null);
        }

        return $summaries;
    }

    /**
     * @param list<EntityId> $forumNodeIds
     * @return list<array{
     *   thread_id:string,
     *   forum_node_id:string,
     *   title:string,
     *   author_username:?string,
     *   activity_at:string
     * }>
     */
    public function recentThreads(array $forumNodeIds, int $limit = 8): array
    {
        if ($forumNodeIds === []) {
            return [];
        }
        if ($limit < 1 || $limit > 30) {
            throw new InvalidArgumentException('Recent thread limit is invalid.');
        }

        [$in, $parameters] = $this->forumIn($forumNodeIds, 'recent_forum');
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT t.thread_id,t.forum_node_id,t.title,u.username AS author_username,'
            . 'COALESCE(MAX(p.updated_at_utc),t.updated_at_utc) AS activity_at '
            . 'FROM forwext_threads t '
            . 'LEFT JOIN forwext_posts p ON p.thread_id=t.thread_id '
            . "AND p.deleted=0 AND p.moderation_state='visible' "
            . 'LEFT JOIN forwext_users u ON u.user_id=t.author_user_id '
            . 'WHERE t.forum_node_id IN (' . $in . ') '
            . "AND t.deleted=0 AND t.archived=0 AND t.merged_into_thread_id IS NULL "
            . "AND t.moderation_state='visible' "
            . 'GROUP BY t.thread_id,t.forum_node_id,t.title,u.username,t.updated_at_utc '
            . 'ORDER BY activity_at DESC,t.thread_id DESC LIMIT ' . $limit,
            $parameters,
        ));

        return array_map(static fn (array $row): array => [
            'thread_id' => (string) $row['thread_id'],
            'forum_node_id' => (string) $row['forum_node_id'],
            'title' => (string) $row['title'],
            'author_username' => self::nullableString($row['author_username'] ?? null),
            'activity_at' => (string) $row['activity_at'],
        ], $rows);
    }

    /**
     * @return array{
     *   rows:list<array{
     *     thread_id:string,
     *     title:string,
     *     sticky:bool,
     *     featured:bool,
     *     locked:bool,
     *     created_at:string,
     *     author_username:?string,
     *     post_count:int,
     *     last_post_at:?string,
     *     last_post_username:?string
     *   }>,
     *   total:int,
     *   page:int,
     *   per_page:int,
     *   pages:int
     * }
     */
    public function threads(EntityId $forumNodeId, int $page = 1, int $perPage = 30): array
    {
        if ($page < 1 || $page > 1_000_000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Forum thread pagination is invalid.');
        }
        $offset = ($page - 1) * $perPage;
        if ($offset > 100_000_000) {
            throw new InvalidArgumentException('Forum thread pagination offset is too large.');
        }

        $parameters = ['forum_node_id' => $forumNodeId->value()];
        $total = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_threads t '
            . 'WHERE t.forum_node_id=:forum_node_id '
            . "AND t.deleted=0 AND t.archived=0 AND t.merged_into_thread_id IS NULL "
            . "AND t.moderation_state='visible'",
            $parameters,
        ));

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT t.thread_id,t.title,t.sticky,t.featured,t.locked,t.created_at_utc,'
            . 'author.username AS author_username,'
            . 'COALESCE(pc.post_count,0) AS post_count,'
            . 'latest.updated_at_utc AS last_post_at,last_user.username AS last_post_username '
            . 'FROM forwext_threads t '
            . 'LEFT JOIN forwext_users author ON author.user_id=t.author_user_id '
            . 'LEFT JOIN ('
            . 'SELECT p.thread_id,COUNT(*) AS post_count,MAX(p.position) AS last_position '
            . 'FROM forwext_posts p '
            . "WHERE p.deleted=0 AND p.moderation_state='visible' GROUP BY p.thread_id"
            . ') pc ON pc.thread_id=t.thread_id '
            . 'LEFT JOIN forwext_posts latest ON latest.thread_id=t.thread_id '
            . 'AND latest.position=pc.last_position AND latest.deleted=0 '
            . "AND latest.moderation_state='visible' "
            . 'LEFT JOIN forwext_users last_user ON last_user.user_id=latest.author_user_id '
            . 'WHERE t.forum_node_id=:forum_node_id '
            . "AND t.deleted=0 AND t.archived=0 AND t.merged_into_thread_id IS NULL "
            . "AND t.moderation_state='visible' "
            . 'ORDER BY t.sticky DESC,t.featured DESC,'
            . 'COALESCE(latest.updated_at_utc,t.updated_at_utc) DESC,t.thread_id DESC '
            . 'LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $parameters,
        ));

        return [
            'rows' => array_map(static fn (array $row): array => [
                'thread_id' => (string) $row['thread_id'],
                'title' => (string) $row['title'],
                'sticky' => (bool) $row['sticky'],
                'featured' => (bool) $row['featured'],
                'locked' => (bool) $row['locked'],
                'created_at' => (string) $row['created_at_utc'],
                'author_username' => self::nullableString($row['author_username'] ?? null),
                'post_count' => max(0, (int) ($row['post_count'] ?? 0)),
                'last_post_at' => self::nullableString($row['last_post_at'] ?? null),
                'last_post_username' => self::nullableString($row['last_post_username'] ?? null),
            ], $rows),
            'total' => max(0, $total),
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil(max(0, $total) / $perPage)),
        ];
    }

    /**
     * @return array{
     *   rows:list<array{
     *     post_id:string,
     *     position:int,
     *     body_source:string,
     *     created_at:string,
     *     updated_at:string,
     *     author_user_id:?string,
     *     author_username:?string,
     *     author_group_name:?string
     *   }>,
     *   total:int,
     *   page:int,
     *   per_page:int,
     *   pages:int
     * }
     */
    public function posts(EntityId $threadId, int $page = 1, int $perPage = 20): array
    {
        if ($page < 1 || $page > 1_000_000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Thread post pagination is invalid.');
        }
        $offset = ($page - 1) * $perPage;
        if ($offset > 100_000_000) {
            throw new InvalidArgumentException('Thread post pagination offset is too large.');
        }

        $parameters = ['thread_id' => $threadId->value()];
        $total = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_posts p WHERE p.thread_id=:thread_id '
            . "AND p.deleted=0 AND p.moderation_state='visible'",
            $parameters,
        ));

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT p.post_id,p.position,p.body_source,p.created_at_utc,p.updated_at_utc,'
            . 'p.author_user_id,u.username AS author_username,g.name AS author_group_name '
            . 'FROM forwext_posts p '
            . 'LEFT JOIN forwext_users u ON u.user_id=p.author_user_id '
            . 'LEFT JOIN forwext_user_primary_groups pg ON pg.user_id=p.author_user_id '
            . 'LEFT JOIN forwext_user_groups g ON g.group_id=pg.group_id '
            . 'WHERE p.thread_id=:thread_id AND p.deleted=0 '
            . "AND p.moderation_state='visible' "
            . 'ORDER BY p.position ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $parameters,
        ));

        return [
            'rows' => array_map(static fn (array $row): array => [
                'post_id' => (string) $row['post_id'],
                'position' => (int) $row['position'],
                'body_source' => (string) $row['body_source'],
                'created_at' => (string) $row['created_at_utc'],
                'updated_at' => (string) $row['updated_at_utc'],
                'author_user_id' => self::nullableString($row['author_user_id'] ?? null),
                'author_username' => self::nullableString($row['author_username'] ?? null),
                'author_group_name' => self::nullableString($row['author_group_name'] ?? null),
            ], $rows),
            'total' => max(0, $total),
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil(max(0, $total) / $perPage)),
        ];
    }

    /** @param list<EntityId> $ids @return array{0:string,1:array<string,string>} */
    private function forumIn(array $ids, string $prefix): array
    {
        $parameters = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $name = $prefix . '_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id->value();
        }

        return [implode(',', $placeholders), $parameters];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
