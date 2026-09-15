<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use Forwext\Core\Domain\Entity\EntityId;

interface ThreadRepository
{
    public function find(EntityId $threadId): ?Thread;

    /** @return list<Thread> */
    public function findByForum(EntityId $forumNodeId, int $limit = 50, int $offset = 0): array;

    public function save(Thread $thread): void;
}
