<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface LayoutBuilderRepository
{
    public function findByKey(string $key): ?LayoutRecord;

    public function revision(EntityId $revisionId): ?LayoutRevision;

    public function saveDraft(
        LayoutRecord $layout,
        LayoutRevision $revision,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): void;

    public function publish(
        EntityId $layoutId,
        EntityId $expectedDraftRevisionId,
        EntityId $actor,
        DateTimeImmutable $updatedAt,
    ): bool;
}
