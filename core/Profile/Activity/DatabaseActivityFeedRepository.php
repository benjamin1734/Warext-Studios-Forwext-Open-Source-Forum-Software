<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use UnexpectedValueException;

final readonly class DatabaseActivityFeedRepository implements ActivityFeedRepository
{
    public function __construct(private QueryExecutor $database) {}

    public function candidates(int $limit = 100, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 300 || $offset < 0 || $offset > 1_000_000) {
            throw new ProfileActivityException('Activity feed pagination is invalid.');
        }

        $sql = "SELECT `activity_type`, `actor_user_id`, `subject_id`, `forum_node_id`, `profile_owner_user_id`, "
            . "`profile_post_id`, `occurred_at_utc`, `summary` FROM ("
            . "SELECT 'thread_created' AS `activity_type`, t.`author_user_id` AS `actor_user_id`, "
            . "t.`thread_id` AS `subject_id`, t.`forum_node_id`, NULL AS `profile_owner_user_id`, "
            . "NULL AS `profile_post_id`, t.`created_at_utc` AS `occurred_at_utc`, LEFT(t.`title`, 240) AS `summary` "
            . "FROM `forwext_threads` t WHERE t.`moderation_state` = 'visible' AND t.`deleted_at_utc` IS NULL "
            . "AND t.`merged_into_thread_id` IS NULL "
            . "UNION ALL "
            . "SELECT 'forum_post_created', p.`author_user_id`, p.`post_id`, t.`forum_node_id`, NULL, NULL, "
            . "p.`created_at_utc`, LEFT(p.`body_source`, 240) FROM `forwext_posts` p "
            . "INNER JOIN `forwext_threads` t ON t.`thread_id` = p.`thread_id` "
            . "WHERE p.`moderation_state` = 'visible' AND p.`deleted_at_utc` IS NULL "
            . "AND t.`moderation_state` = 'visible' AND t.`deleted_at_utc` IS NULL AND t.`merged_into_thread_id` IS NULL "
            . "UNION ALL "
            . "SELECT 'profile_post_created', pp.`author_user_id`, pp.`profile_post_id`, NULL, pp.`profile_owner_user_id`, "
            . "pp.`profile_post_id`, pp.`created_at_utc`, LEFT(pp.`body_source`, 240) FROM `forwext_profile_posts` pp "
            . "WHERE pp.`moderation_state` = 'visible' AND pp.`deleted_at_utc` IS NULL "
            . "UNION ALL "
            . "SELECT 'profile_comment_created', pc.`author_user_id`, pc.`comment_id`, NULL, pp.`profile_owner_user_id`, "
            . "pp.`profile_post_id`, pc.`created_at_utc`, LEFT(pc.`body_source`, 240) FROM `forwext_profile_comments` pc "
            . "INNER JOIN `forwext_profile_posts` pp ON pp.`profile_post_id` = pc.`profile_post_id` "
            . "WHERE pc.`moderation_state` = 'visible' AND pc.`deleted_at_utc` IS NULL "
            . "AND pp.`moderation_state` = 'visible' AND pp.`deleted_at_utc` IS NULL "
            . "UNION ALL "
            . "SELECT 'profile_reaction', r.`user_id`, r.`profile_post_id`, NULL, pp.`profile_owner_user_id`, "
            . "pp.`profile_post_id`, r.`updated_at_utc`, r.`reaction_key` FROM `forwext_profile_post_reactions` r "
            . "INNER JOIN `forwext_profile_posts` pp ON pp.`profile_post_id` = r.`profile_post_id` "
            . "INNER JOIN `forwext_reaction_types` rt ON rt.`reaction_key` = r.`reaction_key` AND rt.`enabled` = 1 "
            . "WHERE pp.`moderation_state` = 'visible' AND pp.`deleted_at_utc` IS NULL"
            . ") feed ORDER BY `occurred_at_utc` DESC, `activity_type`, `subject_id` LIMIT " . $limit . " OFFSET " . $offset;

        return array_map($this->hydrate(...), $this->database->fetchAll(new CompiledQuery($sql)));
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ActivityFeedEntry
    {
        foreach (['activity_type','subject_id','occurred_at_utc','summary'] as $key) {
            if (!array_key_exists($key, $row)) throw new UnexpectedValueException('Activity feed row is incomplete.');
        }
        return new ActivityFeedEntry(
            ActivityFeedType::from((string) $row['activity_type']),
            $this->id($row['actor_user_id'] ?? null),
            EntityId::fromString((string) $row['subject_id']),
            $this->id($row['forum_node_id'] ?? null),
            $this->id($row['profile_owner_user_id'] ?? null),
            $this->id($row['profile_post_id'] ?? null),
            new DateTimeImmutable((string) $row['occurred_at_utc'], new DateTimeZone('UTC')),
            (string) $row['summary'],
        );
    }

    private function id(mixed $value): ?EntityId
    {
        return is_string($value) && $value !== '' ? EntityId::fromString($value) : null;
    }
}
