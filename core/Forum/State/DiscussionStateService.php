<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\State;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Post\PostPermission;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadPermission;
use Forwext\Core\Forum\Thread\ThreadRepository;

final readonly class DiscussionStateService
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private DiscussionStateRepository $state,
        private PermissionGate $gate,
    ) {
    }

    public function saveThreadDraft(
        EntityId $forumNodeId,
        ?string $titleSource,
        string $bodySource,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): ContentDraft {
        [$forum, $hierarchy] = $this->forumContext($forumNodeId);
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forumNodeId);
        $this->gate->require(ThreadPermission::Create->key(), $forumNodeId);
        if ($forum->forumSettings()?->allowNewThreads() !== true) {
            throw new DiscussionStateException('New-thread drafts are disabled for this forum.');
        }
        return $this->state->saveDraft(
            $this->gate->actorId(),
            DraftTargetType::NewThread,
            $forumNodeId,
            $titleSource,
            $bodySource,
            $expectedRevision,
            $at,
        );
    }

    public function saveReplyDraft(
        EntityId $threadId,
        string $bodySource,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): ContentDraft {
        [$thread, $forum, $hierarchy] = $this->threadContext($threadId);
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        $this->gate->require(PostPermission::Create->key(), $forum->id());
        if ($thread->moderationState() !== ThreadModerationState::Visible || $thread->isLocked()) {
            throw new DiscussionStateException('Reply drafts are unavailable for this thread state.');
        }
        if ($forum->forumSettings()?->allowReplies() !== true) {
            throw new DiscussionStateException('Replies are disabled for this forum.');
        }
        return $this->state->saveDraft(
            $this->gate->actorId(),
            DraftTargetType::Reply,
            $threadId,
            null,
            $bodySource,
            $expectedRevision,
            $at,
        );
    }

    public function threadDraft(EntityId $forumNodeId): ?ContentDraft
    {
        [$forum, $hierarchy] = $this->forumContext($forumNodeId);
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        return $this->state->draft($this->gate->actorId(), DraftTargetType::NewThread, $forumNodeId);
    }

    public function replyDraft(EntityId $threadId): ?ContentDraft
    {
        [$thread, $forum, $hierarchy] = $this->threadContext($threadId);
        unset($thread);
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        return $this->state->draft($this->gate->actorId(), DraftTargetType::Reply, $threadId);
    }

    public function deleteDraft(DraftTargetType $targetType, EntityId $targetId): void
    {
        $this->state->deleteDraft($this->gate->actorId(), $targetType, $targetId);
    }

    public function markThreadRead(EntityId $threadId, int $postPosition, DateTimeImmutable $at): void
    {
        [$thread, $forum, $hierarchy] = $this->threadContext($threadId);
        if ($thread->moderationState() !== ThreadModerationState::Visible) {
            throw new DiscussionStateException('Non-visible thread cannot be marked read through the public state service.');
        }
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        $this->state->markThreadRead($this->gate->actorId(), $threadId, $postPosition, $at);
    }

    public function markForumRead(EntityId $forumNodeId, DateTimeImmutable $at): void
    {
        [$forum, $hierarchy] = $this->forumContext($forumNodeId);
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        $this->state->markForumRead($this->gate->actorId(), $forumNodeId, $at);
    }

    public function isThreadUnread(EntityId $threadId): bool
    {
        [$thread, $forum, $hierarchy] = $this->threadContext($threadId);
        if ($thread->moderationState() !== ThreadModerationState::Visible) {
            return false;
        }
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        return $this->state->isThreadUnread($this->gate->actorId(), $threadId);
    }

    public function watchThread(EntityId $threadId, WatchNotificationMode $mode, DateTimeImmutable $at): void
    {
        [$thread, $forum, $hierarchy] = $this->threadContext($threadId);
        if ($thread->moderationState() !== ThreadModerationState::Visible) {
            throw new DiscussionStateException('Only visible threads can be watched.');
        }
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        if ($mode === WatchNotificationMode::None) {
            $this->state->unwatchThread($this->gate->actorId(), $threadId);
            return;
        }
        $this->state->watchThread($this->gate->actorId(), $threadId, $mode, $at);
    }

    public function watchForum(EntityId $forumNodeId, WatchNotificationMode $mode, DateTimeImmutable $at): void
    {
        [$forum, $hierarchy] = $this->forumContext($forumNodeId);
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        if ($mode === WatchNotificationMode::None) {
            $this->state->unwatchForum($this->gate->actorId(), $forumNodeId);
            return;
        }
        $this->state->watchForum($this->gate->actorId(), $forumNodeId, $mode, $at);
    }

    public function threadWatch(EntityId $threadId): ?WatchNotificationMode
    {
        [$thread, $forum, $hierarchy] = $this->threadContext($threadId);
        unset($thread);
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        return $this->state->threadWatch($this->gate->actorId(), $threadId);
    }

    public function forumWatch(EntityId $forumNodeId): ?WatchNotificationMode
    {
        [$forum, $hierarchy] = $this->forumContext($forumNodeId);
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        return $this->state->forumWatch($this->gate->actorId(), $forumNodeId);
    }

    public function subscriptionPreferences(): SubscriptionPreferences
    {
        return $this->state->subscriptionPreferences($this->gate->actorId());
    }

    public function saveSubscriptionPreferences(SubscriptionPreferences $preferences, DateTimeImmutable $at): void
    {
        $this->state->saveSubscriptionPreferences($this->gate->actorId(), $preferences, $at);
    }

    /** @return array{0:ForumNode,1:ForumNodeHierarchy} */
    private function forumContext(EntityId $forumNodeId): array
    {
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $forum = $hierarchy->find($forumNodeId);
        if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
            throw new DiscussionStateException('Forum is not available.');
        }
        return [$forum, $hierarchy];
    }

    /** @return array{0:Thread,1:ForumNode,2:ForumNodeHierarchy} */
    private function threadContext(EntityId $threadId): array
    {
        $thread = $this->threads->find($threadId)
            ?? throw new DiscussionStateException('Thread is not available.');
        [$forum, $hierarchy] = $this->forumContext($thread->forumNodeId());
        return [$thread, $forum, $hierarchy];
    }
}
