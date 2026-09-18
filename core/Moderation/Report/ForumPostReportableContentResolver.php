<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Post\PostModerationState;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;

final readonly class ForumPostReportableContentResolver implements ReportableContentResolver
{
    public function __construct(
        private PostRepository $posts,
        private ThreadRepository $threads,
        private ForumNodeRepository $nodes,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function targetType(): string
    {
        return 'post';
    }

    public function resolve(EntityId $viewerUserId, EntityId $targetId): ?ReportableContent
    {
        $post = $this->posts->find($targetId);
        if ($post === null || $post->isDeleted() || $post->moderationState() !== PostModerationState::Visible) {
            return null;
        }
        $thread = $this->threads->find($post->threadId());
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
            'post',
            $post->id(),
            $thread->title()->value() . ' · #' . $post->position(),
            $post->authorUserId(),
            $nodeId,
        );
    }
}
