<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use InvalidArgumentException;

final readonly class ForumApprovalWorkspaceSource implements ModerationWorkspaceSource
{
    public function __construct(
        private DatabaseConnection $database,
        private ForumNodeRepository $nodes,
        private PermissionGate $gate,
    ) {
    }

    public function section(): ModerationWorkspaceSection
    {
        return ModerationWorkspaceSection::Approval;
    }

    public function count(): int
    {
        $threadNodes = $this->allowedNodeIds('forum.thread.moderate');
        $postNodes = $this->allowedNodeIds('forum.post.moderate');

        return $this->countThreads($threadNodes) + $this->countPosts($postNodes);
    }

    public function latest(int $limit): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Approval workspace limit must be between 1 and 50.');
        }

        $items = array_merge(
            $this->latestThreads($this->allowedNodeIds('forum.thread.moderate'), $limit),
            $this->latestPosts($this->allowedNodeIds('forum.post.moderate'), $limit),
        );
        usort(
            $items,
            static fn (ModerationWorkspaceItem $left, ModerationWorkspaceItem $right): int =>
                [$right->updatedAt->format('U.u'), $right->sourceId]
                <=> [$left->updatedAt->format('U.u'), $left->sourceId],
        );

        return array_slice($items, 0, $limit);
    }

    /** @return list<string> */
    private function allowedNodeIds(string $moderationPermission): array
    {
        $allNodes = $this->nodes->all();
        $hierarchy = new ForumNodeHierarchy($allNodes);
        $view = PermissionKey::fromString('forum.view');
        $moderate = PermissionKey::fromString($moderationPermission);
        $ids = [];

        foreach ($allNodes as $node) {
            if ($node->type() !== ForumNodeType::Forum || !$hierarchy->isResolvable($node->id())) {
                continue;
            }
            if (!$this->gate->allows($view, $node->id()) || !$this->gate->allows($moderate, $node->id())) {
                continue;
            }
            $ids[] = $node->id()->value();
        }

        return $ids;
    }

    /** @param list<string> $nodeIds */
    private function countThreads(array $nodeIds): int
    {
        if ($nodeIds === []) {
            return 0;
        }
        [$in, $parameters] = self::nodeParameters($nodeIds, 'tn');
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_threads` '
            . 'WHERE `forum_node_id` IN (' . $in . ") AND `moderation_state` = 'pending' "
            . 'AND `deleted` = 0 AND `merged_into_thread_id` IS NULL',
            $parameters,
        ));
    }

    /** @param list<string> $nodeIds */
    private function countPosts(array $nodeIds): int
    {
        if ($nodeIds === []) {
            return 0;
        }
        [$in, $parameters] = self::nodeParameters($nodeIds, 'pn');
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_posts` p '
            . 'INNER JOIN `forwext_threads` t ON t.`thread_id` = p.`thread_id` '
            . 'WHERE t.`forum_node_id` IN (' . $in . ") AND p.`moderation_state` = 'pending' "
            . 'AND p.`deleted` = 0 AND t.`deleted` = 0 AND t.`merged_into_thread_id` IS NULL',
            $parameters,
        ));
    }

    /** @param list<string> $nodeIds @return list<ModerationWorkspaceItem> */
    private function latestThreads(array $nodeIds, int $limit): array
    {
        if ($nodeIds === []) {
            return [];
        }
        [$in, $parameters] = self::nodeParameters($nodeIds, 'tl');
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `thread_id`, `title`, `updated_at_utc` FROM `forwext_threads` '
            . 'WHERE `forum_node_id` IN (' . $in . ") AND `moderation_state` = 'pending' "
            . 'AND `deleted` = 0 AND `merged_into_thread_id` IS NULL '
            . 'ORDER BY `updated_at_utc` DESC, `thread_id` DESC LIMIT ' . $limit,
            $parameters,
        ));

        return array_map(
            static fn (array $row): ModerationWorkspaceItem => new ModerationWorkspaceItem(
                ModerationWorkspaceSection::Approval,
                'forum.thread',
                (string) $row['thread_id'],
                (string) $row['title'],
                'pending',
                new DateTimeImmutable((string) $row['updated_at_utc'], new DateTimeZone('UTC')),
                'Onay bekleyen konu',
            ),
            $rows,
        );
    }

    /** @param list<string> $nodeIds @return list<ModerationWorkspaceItem> */
    private function latestPosts(array $nodeIds, int $limit): array
    {
        if ($nodeIds === []) {
            return [];
        }
        [$in, $parameters] = self::nodeParameters($nodeIds, 'pl');
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT p.`post_id`, p.`position`, p.`updated_at_utc`, t.`title` AS `thread_title` '
            . 'FROM `forwext_posts` p INNER JOIN `forwext_threads` t ON t.`thread_id` = p.`thread_id` '
            . 'WHERE t.`forum_node_id` IN (' . $in . ") AND p.`moderation_state` = 'pending' "
            . 'AND p.`deleted` = 0 AND t.`deleted` = 0 AND t.`merged_into_thread_id` IS NULL '
            . 'ORDER BY p.`updated_at_utc` DESC, p.`post_id` DESC LIMIT ' . $limit,
            $parameters,
        ));

        return array_map(
            static fn (array $row): ModerationWorkspaceItem => new ModerationWorkspaceItem(
                ModerationWorkspaceSection::Approval,
                'forum.post',
                (string) $row['post_id'],
                'Yanıt #' . (int) $row['position'] . ' — ' . (string) $row['thread_title'],
                'pending',
                new DateTimeImmutable((string) $row['updated_at_utc'], new DateTimeZone('UTC')),
                'Onay bekleyen yanıt',
            ),
            $rows,
        );
    }

    /** @param list<string> $ids @return array{0:string,1:array<string,string>} */
    private static function nodeParameters(array $ids, string $prefix): array
    {
        $placeholders = [];
        $parameters = [];
        foreach ($ids as $index => $id) {
            $name = $prefix . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }
        return [implode(', ', $placeholders), $parameters];
    }
}
