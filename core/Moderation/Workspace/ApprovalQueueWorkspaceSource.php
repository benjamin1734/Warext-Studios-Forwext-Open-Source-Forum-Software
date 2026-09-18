<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

use Forwext\Core\Moderation\Approval\ApprovalQueueItem;
use Forwext\Core\Moderation\Approval\ApprovalQueueRegistry;

final readonly class ApprovalQueueWorkspaceSource implements ModerationWorkspaceSource
{
    public function __construct(private ApprovalQueueRegistry $queue)
    {
    }

    public function section(): ModerationWorkspaceSection
    {
        return ModerationWorkspaceSection::Approval;
    }

    public function count(): int
    {
        return $this->queue->count();
    }

    public function latest(int $limit): array
    {
        return array_map(
            static fn (ApprovalQueueItem $item): ModerationWorkspaceItem => new ModerationWorkspaceItem(
                ModerationWorkspaceSection::Approval,
                $item->sourceType,
                $item->sourceId->value(),
                $item->title,
                'pending',
                $item->updatedAt,
                $item->summary,
                '/moderation/approval',
            ),
            $this->queue->latest($limit),
        );
    }
}
