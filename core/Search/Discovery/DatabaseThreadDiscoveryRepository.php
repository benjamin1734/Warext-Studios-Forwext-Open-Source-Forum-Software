<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Discovery;

use DateInterval;
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

final readonly class DatabaseThreadDiscoveryRepository implements ThreadDiscoveryRepository
{
    private const TREND_WINDOW = 'P7D';

    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function discover(
        EntityId $userId,
        array $forumNodeIds,
        DiscoveryMode $mode,
        DateTimeImmutable $now,
        int $limit,
        int $offset,
    ): array {
        UserId::assert($userId);
        if ($forumNodeIds === []) {
            throw new InvalidArgumentException('Discovery requires at least one visible forum.');
        }
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000) {
            throw new InvalidArgumentException('Discovery pagination is invalid.');
        }

        $parameters = [
            'read_user_id' => $userId->value(),
            'forum_user_id' => $userId->value(),
            'trend_since' => self::format(
                $now->setTimezone(new DateTimeZone('UTC'))->sub(new DateInterval(self::TREND_WINDOW)),
            ),
        ];
        $placeholders = [];
        foreach (array_values($forumNodeIds) as $index => $forumNodeId) {
            ForumNodeId::fromStored($forumNodeId);
            $name = 'forum_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $forumNodeId;
        }

        $activityExpression = 'COALESCE(MAX(`p`.`updated_at_utc`), `t`.`created_at_utc`)';
        $recentExpression = 'SUM(CASE WHEN `p`.`updated_at_utc` >= :trend_since THEN 1 ELSE 0 END)';
        $latestPositionExpression = 'COALESCE(MAX(`p`.`position`), 0)';
        $unreadExpression = $latestPositionExpression . ' > COALESCE(`r`.`last_read_post_position`, 0)'
            . ' AND (`f`.`marked_read_at_utc` IS NULL OR '
            . $activityExpression . ' > `f`.`marked_read_at_utc`)';

        $where = [
            '`t`.`forum_node_id` IN (' . implode(', ', $placeholders) . ')',
            '`t`.`deleted` = 0',
            '`t`.`merged_into_thread_id` IS NULL',
            "`t`.`moderation_state` = 'visible'",
        ];
        $having = [];
        $order = match ($mode) {
            DiscoveryMode::New => '`t`.`created_at_utc` DESC, `t`.`thread_id` DESC',
            DiscoveryMode::Unread => '`activity_at_utc` DESC, `t`.`thread_id` DESC',
            DiscoveryMode::Trending => '`recent_post_count` DESC, `activity_at_utc` DESC, `t`.`thread_id` DESC',
            DiscoveryMode::Featured => '`activity_at_utc` DESC, `t`.`thread_id` DESC',
            DiscoveryMode::RecentActivity => '`activity_at_utc` DESC, `t`.`thread_id` DESC',
        };

        if ($mode === DiscoveryMode::Featured) {
            $where[] = '`t`.`featured` = 1';
        } elseif ($mode === DiscoveryMode::Unread) {
            $having[] = '`unread` = 1';
        } elseif ($mode === DiscoveryMode::Trending) {
            $having[] = '`recent_post_count` > 0';
        }

        $sql = 'SELECT `t`.`thread_id`, `t`.`forum_node_id`, `t`.`author_user_id`, `t`.`title`, '
            . '`t`.`featured`, `t`.`created_at_utc`, '
            . $activityExpression . ' AS `activity_at_utc`, '
            . 'COUNT(`p`.`post_id`) AS `visible_post_count`, '
            . $recentExpression . ' AS `recent_post_count`, '
            . 'CASE WHEN ' . $unreadExpression . ' THEN 1 ELSE 0 END AS `unread` '
            . 'FROM `forwext_threads` `t` '
            . 'LEFT JOIN `forwext_posts` `p` ON `p`.`thread_id` = `t`.`thread_id` '
            . 'AND `p`.`deleted` = 0 AND `p`.`moderation_state` = \'visible\' '
            . 'LEFT JOIN `forwext_thread_read_state` `r` ON `r`.`thread_id` = `t`.`thread_id` '
            . 'AND `r`.`user_id` = :read_user_id '
            . 'LEFT JOIN `forwext_forum_read_state` `f` ON `f`.`forum_node_id` = `t`.`forum_node_id` '
            . 'AND `f`.`user_id` = :forum_user_id '
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . 'GROUP BY `t`.`thread_id`, `t`.`forum_node_id`, `t`.`author_user_id`, `t`.`title`, '
            . '`t`.`featured`, `t`.`created_at_utc`, `r`.`last_read_post_position`, `f`.`marked_read_at_utc` '
            . ($having === [] ? '' : 'HAVING ' . implode(' AND ', $having) . ' ')
            . 'ORDER BY ' . $order . ' '
            . 'LIMIT ' . $limit . ' OFFSET ' . $offset;

        $rows = $this->database->fetchAll(new CompiledQuery($sql, $parameters));

        return array_map(self::hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): DiscoveryThread
    {
        foreach ([
            'thread_id',
            'forum_node_id',
            'title',
            'featured',
            'created_at_utc',
            'activity_at_utc',
            'visible_post_count',
            'recent_post_count',
            'unread',
        ] as $required) {
            if (!array_key_exists($required, $row)) {
                throw new RuntimeException('Discovery row is missing required data.');
            }
        }

        $authorUserId = null;
        if (($row['author_user_id'] ?? null) !== null) {
            $authorUserId = UserId::fromStored((string) $row['author_user_id']);
        }

        return new DiscoveryThread(
            ThreadId::fromStored((string) $row['thread_id']),
            ForumNodeId::fromStored((string) $row['forum_node_id']),
            $authorUserId,
            (string) $row['title'],
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['activity_at_utc']),
            (bool) $row['featured'],
            (bool) $row['unread'],
            (int) $row['visible_post_count'],
            (int) $row['recent_post_count'],
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC'),
        );
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored discovery timestamp is invalid.');
        }

        return $parsed;
    }
}
