<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final class ForumNodeHierarchy
{
    private const MAX_DEPTH = 64;

    /** @var array<string, ForumNode> */
    private array $nodes = [];

    /** @var array<string, string> */
    private array $slugToId = [];

    /** @param list<ForumNode> $nodes */
    public function __construct(array $nodes)
    {
        foreach ($nodes as $node) {
            $id = $node->id()->value();
            $slug = $node->slug()->value();
            if (isset($this->nodes[$id])) {
                throw new InvalidArgumentException('Forum hierarchy contains a duplicate node id.');
            }
            if (isset($this->slugToId[$slug])) {
                throw new InvalidArgumentException('Forum hierarchy contains a duplicate node slug.');
            }
            $this->nodes[$id] = $node;
            $this->slugToId[$slug] = $id;
        }

        foreach ($this->nodes as $node) {
            $parentId = $node->parentId();
            if ($parentId !== null) {
                $parent = $this->nodes[$parentId->value()] ?? null;
                if ($parent === null) {
                    throw new InvalidArgumentException('Forum hierarchy contains an orphan node.');
                }
                if (!$parent->canContainChildren()) {
                    throw new InvalidArgumentException('Page and link nodes cannot contain child nodes.');
                }
            }
            $this->assertAcyclicAndBounded($node);
        }
    }

    /** @return list<ForumNode> */
    public function all(): array
    {
        return array_values($this->nodes);
    }

    public function find(EntityId $nodeId): ?ForumNode
    {
        return $this->nodes[$nodeId->value()] ?? null;
    }

    public function findBySlug(ForumNodeSlug $slug): ?ForumNode
    {
        $id = $this->slugToId[$slug->value()] ?? null;
        return $id === null ? null : $this->nodes[$id];
    }

    /** @return list<ForumNode> */
    public function children(?EntityId $parentId): array
    {
        $parent = $parentId?->value();
        $children = array_values(array_filter(
            $this->nodes,
            static fn (ForumNode $node): bool => $node->parentId()?->value() === $parent,
        ));

        usort($children, static function (ForumNode $left, ForumNode $right): int {
            return [$left->sortOrder(), $left->title(), $left->slug()->value(), $left->id()->value()]
                <=> [$right->sortOrder(), $right->title(), $right->slug()->value(), $right->id()->value()];
        });

        return $children;
    }

    /** @return list<ForumNode> */
    public function navigationChildren(?EntityId $parentId): array
    {
        return array_values(array_filter(
            $this->children($parentId),
            fn (ForumNode $node): bool => $this->isDiscoverable($node->id()),
        ));
    }

    /** @return list<ForumNode> root-to-node */
    public function breadcrumb(EntityId $nodeId): array
    {
        $node = $this->find($nodeId);
        if ($node === null) {
            throw new InvalidArgumentException('Forum node is not part of this hierarchy.');
        }

        $result = [];
        $current = $node;
        while (true) {
            $result[] = $current;
            $parentId = $current->parentId();
            if ($parentId === null) {
                break;
            }
            $current = $this->nodes[$parentId->value()];
        }

        return array_reverse($result);
    }

    public function isResolvable(EntityId $nodeId): bool
    {
        $node = $this->find($nodeId);
        if ($node === null) {
            return false;
        }

        foreach ($this->breadcrumb($nodeId) as $ancestor) {
            if ($ancestor->visibility() === ForumNodeVisibility::Disabled) {
                return false;
            }
        }

        return true;
    }

    public function isDiscoverable(EntityId $nodeId): bool
    {
        if (!$this->isResolvable($nodeId)) {
            return false;
        }

        foreach ($this->breadcrumb($nodeId) as $ancestor) {
            if ($ancestor->visibility() !== ForumNodeVisibility::Listed) {
                return false;
            }
        }

        return true;
    }

    private function assertAcyclicAndBounded(ForumNode $start): void
    {
        $seen = [];
        $current = $start;
        $depth = 0;

        while (true) {
            $id = $current->id()->value();
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Forum hierarchy contains a parent cycle.');
            }
            $seen[$id] = true;
            $depth++;
            if ($depth > self::MAX_DEPTH) {
                throw new InvalidArgumentException('Forum hierarchy exceeds the maximum supported depth.');
            }

            $parentId = $current->parentId();
            if ($parentId === null) {
                return;
            }
            $current = $this->nodes[$parentId->value()];
        }
    }
}
