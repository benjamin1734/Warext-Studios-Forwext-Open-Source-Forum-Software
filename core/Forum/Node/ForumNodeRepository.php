<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

use Forwext\Core\Domain\Entity\EntityId;

interface ForumNodeRepository
{
    public function find(EntityId $nodeId): ?ForumNode;

    public function findBySlug(ForumNodeSlug $slug): ?ForumNode;

    /** @return list<ForumNode> */
    public function all(): array;

    public function save(ForumNode $node): void;

    public function delete(EntityId $nodeId): void;
}
