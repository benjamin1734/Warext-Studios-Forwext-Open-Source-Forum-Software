<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;

final readonly class ForumThreadReportableContentResolver implements ReportableContentResolver
{
    public function __construct(
        private ThreadRepository $threads,
        private ForumNodeRepository $nodes,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function targetType(): string
    {
        return 'thread';
    }

    public function resolve(EntityId $viewerUserId, EntityId $targetId): ?ReportableContent
    {
        $thread = $this->threads->find($targetId);
        if ($thread === null || $thread->moderationState() !== ThreadModerationState::Visible) {
            return null;
        }

        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $nodeId = $thread->forumNodeId();
        if (!$hierarchy->isResolvable($nodeId)
            || !$this->authorizer->allows($viewerUserId, PermissionKey::fromString('forum.view'), $nodeId)
        ) {
            return null;
        }

        return new ReportableContent(
            'thread',
            $thread->id(),
            $thread->title()->value(),
            $thread->authorUserId(),
            $nodeId,
        );
    }
}
