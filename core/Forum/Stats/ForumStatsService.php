<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Stats;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Access\SearchAccessScopeProvider;

final readonly class ForumStatsService
{
    private const FORUM_SCOPE_PREFIX = 'forum.node:';

    public function __construct(
        private QueryExecutor $database,
        private SearchAccessScopeProvider $forumScopes,
    ) {
    }

    public function forUser(EntityId $userId): ForumStats
    {
        $forumIds = [];
        foreach ($this->forumScopes->scopes($userId) as $scope) {
            if (str_starts_with($scope, self::FORUM_SCOPE_PREFIX)) {
                $id = substr($scope, strlen(self::FORUM_SCOPE_PREFIX));
                if ($id !== '') {
                    $forumIds[$id] = true;
                }
            }
        }

        $members = (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `forwext_users` WHERE `status` = 'active'",
        ));
        if ($forumIds === []) {
            return new ForumStats(0, 0, 0, $members);
        }

        $parameters = [];
        $placeholders = [];
        foreach (array_keys($forumIds) as $index => $id) {
            $name = 'forum_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }
        $in = implode(', ', $placeholders);

        $forums = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_nodes` WHERE `node_id` IN (' . $in . ') '
            . "AND `node_type` = 'forum'",
            $parameters,
        ));
        $threads = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_threads` WHERE `forum_node_id` IN (' . $in . ') '
            . "AND `deleted` = 0 AND `moderation_state` = 'visible' AND `merged_into_thread_id` IS NULL",
            $parameters,
        ));
        $posts = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_posts` `p` '
            . 'INNER JOIN `forwext_threads` `t` ON `t`.`thread_id` = `p`.`thread_id` '
            . 'WHERE `t`.`forum_node_id` IN (' . $in . ') '
            . "AND `t`.`deleted` = 0 AND `t`.`moderation_state` = 'visible' "
            . 'AND `t`.`merged_into_thread_id` IS NULL '
            . "AND `p`.`deleted` = 0 AND `p`.`moderation_state` = 'visible'",
            $parameters,
        ));

        return new ForumStats($forums, $threads, $posts, $members);
    }
}
