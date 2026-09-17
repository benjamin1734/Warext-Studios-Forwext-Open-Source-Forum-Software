<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

use Forwext\Core\Moderation\Task\ModerationTask;
use Forwext\Core\Moderation\Task\ModerationTaskRepository;

final readonly class ModerationTaskWorkspaceSource implements ModerationWorkspaceSource
{
    public function __construct(private ModerationTaskRepository $tasks)
    {
    }

    public function section(): ModerationWorkspaceSection
    {
        return ModerationWorkspaceSection::Tasks;
    }

    public function count(): int
    {
        return $this->tasks->countActive();
    }

    public function latest(int $limit): array
    {
        return array_map(
            static fn (ModerationTask $task): ModerationWorkspaceItem => new ModerationWorkspaceItem(
                ModerationWorkspaceSection::Tasks,
                'moderation.task',
                $task->id->value(),
                $task->title,
                $task->status->value,
                $task->updatedAt,
                $task->priority->value,
            ),
            $this->tasks->latestActive($limit),
        );
    }
}
