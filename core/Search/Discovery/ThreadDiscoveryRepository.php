<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Discovery;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ThreadDiscoveryRepository
{
    /**
     * @param non-empty-list<string> $forumNodeIds
     * @return list<DiscoveryThread>
     */
    public function discover(
        EntityId $userId,
        array $forumNodeIds,
        DiscoveryMode $mode,
        DateTimeImmutable $now,
        int $limit,
        int $offset,
    ): array;
}
