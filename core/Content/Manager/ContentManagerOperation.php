<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class ContentManagerOperation
{
    public function __construct(
        public EntityId $operationId,
        public ?EntityId $actorUserId,
        public EntityId $targetUserId,
        public ContentManagerAction $action,
        public ?ContentManagerContentType $contentType,
        public ?EntityId $targetForumNodeId,
        public ContentManagerOperationStatus $status,
        public int $totalCount,
        public int $processedCount,
        public int $succeededCount,
        public int $skippedCount,
        public int $failedCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
    ) {
    }

    public function percent(): int
    {
        if ($this->totalCount < 1) {
            return 100;
        }
        return min(100, (int) floor(($this->processedCount / $this->totalCount) * 100));
    }
}
