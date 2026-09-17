<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Task;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ModerationTaskRepository
{
    public function save(ModerationTask $task): void;

    public function find(EntityId $taskId): ?ModerationTask;

    public function updateStatus(EntityId $taskId, ModerationTaskStatus $status, DateTimeImmutable $updatedAt): void;

    public function countActive(): int;

    /** @return list<ModerationTask> */
    public function latestActive(int $limit): array;
}
