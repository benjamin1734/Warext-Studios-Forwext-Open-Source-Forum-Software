<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Approval;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\BulkPostAction;
use Forwext\Core\Forum\Moderation\BulkThreadAction;
use Forwext\Core\Forum\Moderation\ContentModerationService;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use InvalidArgumentException;

final readonly class ForumApprovalQueueProvider implements ApprovalQueueProvider
{
    private const THREAD = 'forum.thread';
    private const POST = 'forum.post';

    public function __construct(
        private DatabaseConnection $database,
        private ForumNodeRepository $nodes,
        private PermissionGate $gate,
        private ContentModerationService $moderation,
    ) {
    }

    public function sourceTypes(): array
    {
        return [self::THREAD, self::POST];
    }

    public function count(): int
    {
        return $this->countThreads($this->allowedNodeIds('forum.thread.moderate'))
            + $this->countPosts($this->allowedNodeIds('forum.post.moderate'));
    }

    public function latest(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Forum approval queue limit must be between 1 and 100.');
        }
        $items = array_merge(
            $this->latestThreads($this->allowedNodeIds('forum.thread.moderate'), $limit),
            $this->latestPosts($this->allowedNodeIds('forum.post.moderate'), $limit),
        );
        usort(
            $items,
            static fn (ApprovalQueueItem $left, ApprovalQueueItem $right): int =>
                [$right->updatedAt->format('U.u'), $right->sourceId->value()]
                <=> [$left->updatedAt->format('U.u'), $left->sourceId->value()],
        );
        return array_slice($items, 0, $limit);
    }

    public function moderate(
        ApprovalQueueAction $action,
        array $selections,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $threads = [];
        $posts = [];
        foreach ($selections as $selection) {
            if (!$selection instanceof ApprovalQueueSelection) {
                throw new InvalidArgumentException('Forum approval selection is invalid.');
            }
            match ($selection->sourceType) {
                self::THREAD => $threads[$selection->sourceId->value()] = $selection->sourceId,
                self::POST => $posts[$selection->sourceId->value()] = $selection->sourceId,
                default => throw new InvalidArgumentException('Forum approval source type is not supported.'),
            };
        }

        if ($threads !== []) {
            $this->moderation->bulkThreads(
                $action === ApprovalQueueAction::Approve ? BulkThreadAction::Approve : BulkThreadAction::Reject,
                array_values($threads),
                $reason,
                $requestId,
                $at,
            );
        }
        if ($posts !== []) {
            $this->moderation->bulkPosts(
                $action === ApprovalQueueAction::Approve ? BulkPostAction::Approve : BulkPostAction::Reject,
                array_values($posts),
                $reason,
                $requestId,
                $at,
            );
        }
    }

    /** @return list<string> */
    private function allowedNodeIds(string $moderationPermission): array
    {
        $allNodes = $this->nodes->all();
        $hierarchy = new ForumNodeHierarchy($allNodes);
        $view = PermissionKey::fromString('forum.view');
        $moderate = PermissionKey::fromString($moderationPermission);
        $bulk = PermissionKey::fromString('forum.moderation.bulk');
        $ids = [];
        foreach ($allNodes as $node) {
            if ($node->type() !== ForumNodeType::Forum || !$hierarchy->isResolvable($node->id())) {
                continue;
            }
            if (
                $this->gate->allows($view, $node->id())
                && $this->gate->allows($moderate, $node->id())
                && $this->gate->allows($bulk, $node->id())
            ) {
                $ids[] = $node->id()->value();
            }
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
            'SELECT COUNT(*) FROM `forwext_threads` WHERE `forum_node_id` IN (' . $in . ") "
            . "AND `moderation_state` = 'pending' AND `deleted` = 0 AND `merged_into_thread_id` IS NULL",
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
            'SELECT COUNT(*) FROM `forwext_posts` p INNER JOIN `forwext_threads` t ON t.`thread_id` = p.`thread_id` '
            . 'WHERE t.`forum_node_id` IN (' . $in . ") AND p.`moderation_state` = 'pending' "
            . 'AND p.`deleted` = 0 AND t.`deleted` = 0 AND t.`merged_into_thread_id` IS NULL',
            $parameters,
        ));
    }

    /** @param list<string> $nodeIds @return list<ApprovalQueueItem> */
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
            static fn (array $row): ApprovalQueueItem => new ApprovalQueueItem(
                self::THREAD,
                EntityId::fromString((string) $row['thread_id']),
                (string) $row['title'],
                new DateTimeImmutable((string) $row['updated_at_utc'], new DateTimeZone('UTC')),
                'Onay bekleyen konu',
            ),
            $rows,
        );
    }

    /** @param list<string> $nodeIds @return list<ApprovalQueueItem> */
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
            static fn (array $row): ApprovalQueueItem => new ApprovalQueueItem(
                self::POST,
                EntityId::fromString((string) $row['post_id']),
                'Yanıt #' . (int) $row['position'] . ' — ' . (string) $row['thread_title'],
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
