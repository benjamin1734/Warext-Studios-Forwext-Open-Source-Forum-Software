<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ContentManagerOperationRepository
{
    /** @param list<ContentManagerTarget> $targets */
    public function create(
        EntityId $operationId,
        EntityId $actorUserId,
        ContentManagerFilter $filter,
        ContentManagerAction $action,
        ?EntityId $targetForumNodeId,
        array $targets,
        DateTimeImmutable $at,
    ): void;

    public function find(EntityId $operationId): ?ContentManagerOperation;

    /** @return list<ContentManagerOperation> */
    public function recentForActor(EntityId $actorUserId, int $limit = 20): array;

    /** @return list<ContentManagerOperationItem> */
    public function items(EntityId $operationId, int $limit = 200): array;

    /** @return list<ContentManagerOperationItem> */
    public function claim(EntityId $operationId, int $limit, DateTimeImmutable $at): array;

    public function completeItem(
        EntityId $operationId,
        ContentManagerContentType $type,
        EntityId $contentId,
        ContentManagerItemStatus $status,
        ?string $failureCode,
        DateTimeImmutable $at,
    ): void;

    public function refreshProgress(EntityId $operationId, DateTimeImmutable $at): ContentManagerOperation;
}
