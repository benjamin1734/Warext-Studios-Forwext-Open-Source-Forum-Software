<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

interface ContentManagerRepository
{
    /** @return list<ContentManagerItem> */
    public function search(ContentManagerFilter $filter, int $limit = 100, int $offset = 0): array;

    /** @return list<ContentManagerTarget> */
    public function targets(ContentManagerFilter $filter, int $limit = 501): array;

    public function current(ContentManagerContentType $type, \Forwext\Core\Domain\Entity\EntityId $id): ?ContentManagerTarget;

    public function source(ContentManagerContentType $type, \Forwext\Core\Domain\Entity\EntityId $id): ?string;

    /** @return list<\Forwext\Core\Domain\Entity\EntityId> */
    public function postIdsForThread(\Forwext\Core\Domain\Entity\EntityId $threadId, int $limit = 10000): array;
}
