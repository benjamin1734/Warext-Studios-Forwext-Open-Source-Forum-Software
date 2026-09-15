<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class DatabaseForumNodeRepository implements ForumNodeRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function find(EntityId $nodeId): ?ForumNode
    {
        ForumNodeId::assert($nodeId);
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectSql() . ' WHERE n.`node_id` = :node_id LIMIT 1',
            ['node_id' => $nodeId->value()],
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(ForumNodeSlug $slug): ?ForumNode
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectSql() . ' WHERE n.`slug` = :slug LIMIT 1',
            ['slug' => $slug->value()],
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map(
            $this->hydrate(...),
            $this->database->fetchAll(new CompiledQuery(
                $this->selectSql() . ' ORDER BY n.`sort_order`, n.`title`, n.`node_id`',
            )),
        );
    }

    public function save(ForumNode $node): void
    {
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($node): void {
            $rows = $database->fetchAll(new CompiledQuery(
                $this->selectSql() . ' ORDER BY n.`node_id` FOR UPDATE',
                [],
                true,
            ));
            $nodes = [];
            $replaced = false;
            foreach ($rows as $row) {
                $current = $this->hydrate($row);
                if ($current->id()->equals($node->id())) {
                    $nodes[] = $node;
                    $replaced = true;
                } else {
                    $nodes[] = $current;
                }
            }
            if (!$replaced) {
                $nodes[] = $node;
            }
            new ForumNodeHierarchy($nodes);

            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_nodes` '
                . '(`node_id`, `parent_id`, `node_type`, `title`, `slug`, `description`, `visibility`, '
                . '`sort_order`, `page_content`, `link_target`, `link_new_window`, `created_at_utc`, `updated_at_utc`) '
                . 'VALUES (:node_id, :parent_id, :node_type, :title, :slug, :description, :visibility, '
                . ':sort_order, :page_content, :link_target, :link_new_window, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE `parent_id` = VALUES(`parent_id`), `node_type` = VALUES(`node_type`), '
                . '`title` = VALUES(`title`), `slug` = VALUES(`slug`), `description` = VALUES(`description`), '
                . '`visibility` = VALUES(`visibility`), `sort_order` = VALUES(`sort_order`), '
                . '`page_content` = VALUES(`page_content`), `link_target` = VALUES(`link_target`), '
                . '`link_new_window` = VALUES(`link_new_window`), `updated_at_utc` = VALUES(`updated_at_utc`)',
                [
                    'node_id' => $node->id()->value(),
                    'parent_id' => $node->parentId()?->value(),
                    'node_type' => $node->type()->value,
                    'title' => $node->title(),
                    'slug' => $node->slug()->value(),
                    'description' => $node->description(),
                    'visibility' => $node->visibility()->value,
                    'sort_order' => $node->sortOrder(),
                    'page_content' => $node->pageContent(),
                    'link_target' => $node->linkTarget()?->value(),
                    'link_new_window' => $node->linkNewWindow(),
                ],
            ));

            $settings = $node->forumSettings();
            if ($settings === null) {
                $database->execute(new CompiledQuery(
                    'DELETE FROM `forwext_forum_settings` WHERE `node_id` = :node_id',
                    ['node_id' => $node->id()->value()],
                ));
                return;
            }

            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_forum_settings` '
                . '(`node_id`, `allow_new_threads`, `allow_replies`, `require_thread_approval`, '
                . '`require_post_approval`, `default_thread_sort`, `threads_per_page`, `updated_at_utc`) '
                . 'VALUES (:node_id, :allow_new_threads, :allow_replies, :require_thread_approval, '
                . ':require_post_approval, :default_thread_sort, :threads_per_page, UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE `allow_new_threads` = VALUES(`allow_new_threads`), '
                . '`allow_replies` = VALUES(`allow_replies`), '
                . '`require_thread_approval` = VALUES(`require_thread_approval`), '
                . '`require_post_approval` = VALUES(`require_post_approval`), '
                . '`default_thread_sort` = VALUES(`default_thread_sort`), '
                . '`threads_per_page` = VALUES(`threads_per_page`), `updated_at_utc` = VALUES(`updated_at_utc`)',
                [
                    'node_id' => $node->id()->value(),
                    'allow_new_threads' => $settings->allowNewThreads(),
                    'allow_replies' => $settings->allowReplies(),
                    'require_thread_approval' => $settings->requireThreadApproval(),
                    'require_post_approval' => $settings->requirePostApproval(),
                    'default_thread_sort' => $settings->defaultThreadSort()->value,
                    'threads_per_page' => $settings->threadsPerPage(),
                ],
            ));
        });
    }

    public function delete(EntityId $nodeId): void
    {
        ForumNodeId::assert($nodeId);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($nodeId): void {
            $children = $database->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM `forwext_nodes` WHERE `parent_id` = :node_id FOR UPDATE',
                ['node_id' => $nodeId->value()],
                true,
            ));
            if ((int) $children > 0) {
                throw new InvalidArgumentException('Forum node with children cannot be deleted.');
            }

            $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_nodes` WHERE `node_id` = :node_id',
                ['node_id' => $nodeId->value()],
            ));
        });
    }

    private function selectSql(): string
    {
        return 'SELECT n.`node_id`, n.`parent_id`, n.`node_type`, n.`title`, n.`slug`, n.`description`, '
            . 'n.`visibility`, n.`sort_order`, n.`page_content`, n.`link_target`, n.`link_new_window`, '
            . 's.`allow_new_threads`, s.`allow_replies`, s.`require_thread_approval`, '
            . 's.`require_post_approval`, s.`default_thread_sort`, s.`threads_per_page` '
            . 'FROM `forwext_nodes` n LEFT JOIN `forwext_forum_settings` s ON s.`node_id` = n.`node_id`';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ForumNode
    {
        foreach (['node_id', 'node_type', 'title', 'slug', 'description', 'visibility', 'sort_order'] as $required) {
            if (!array_key_exists($required, $row)) {
                throw new UnexpectedValueException('Forum node row is missing required data.');
            }
        }

        $id = ForumNodeId::fromStored((string) $row['node_id']);
        $parentId = ($row['parent_id'] ?? null) === null
            ? null
            : ForumNodeId::fromStored((string) $row['parent_id']);
        $type = ForumNodeType::from((string) $row['node_type']);
        $slug = ForumNodeSlug::fromString((string) $row['slug']);
        $visibility = ForumNodeVisibility::from((string) $row['visibility']);
        $common = [
            $id,
            $parentId,
            (string) $row['title'],
            $slug,
        ];
        $description = (string) $row['description'];
        $sortOrder = (int) $row['sort_order'];

        return match ($type) {
            ForumNodeType::Category => ForumNode::category(
                ...[...$common, $description, $sortOrder, $visibility],
            ),
            ForumNodeType::Forum => ForumNode::forum(
                ...[
                    ...$common,
                    $this->hydrateSettings($row),
                    $description,
                    $sortOrder,
                    $visibility,
                ],
            ),
            ForumNodeType::Page => ForumNode::page(
                ...[
                    ...$common,
                    (string) ($row['page_content'] ?? ''),
                    $description,
                    $sortOrder,
                    $visibility,
                ],
            ),
            ForumNodeType::Link => ForumNode::link(
                ...[
                    ...$common,
                    ForumNodeLinkTarget::fromString((string) ($row['link_target'] ?? '')),
                    (bool) ($row['link_new_window'] ?? false),
                    $description,
                    $sortOrder,
                    $visibility,
                ],
            ),
        };
    }

    /** @param array<string, mixed> $row */
    private function hydrateSettings(array $row): ForumSettings
    {
        foreach ([
            'allow_new_threads',
            'allow_replies',
            'require_thread_approval',
            'require_post_approval',
            'default_thread_sort',
            'threads_per_page',
        ] as $required) {
            if (($row[$required] ?? null) === null) {
                throw new UnexpectedValueException('Forum node is missing persisted forum settings.');
            }
        }

        return new ForumSettings(
            (bool) $row['allow_new_threads'],
            (bool) $row['allow_replies'],
            (bool) $row['require_thread_approval'],
            (bool) $row['require_post_approval'],
            ForumDefaultThreadSort::from((string) $row['default_thread_sort']),
            (int) $row['threads_per_page'],
        );
    }
}
