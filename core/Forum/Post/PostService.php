<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTypeRegistry;

final readonly class PostService
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private ThreadTypeRegistry $types,
        private PostRepository $posts,
        private PermissionGate $gate,
    ) {
    }

    public function createFirstPost(EntityId $threadId, PostBody $body, DateTimeImmutable $now): Post
    {
        [$thread, $hierarchy] = $this->threadContext($threadId);
        $actor = $this->gate->actorId();
        if ($thread->authorUserId() === null || !$thread->authorUserId()->equals($actor)) {
            throw new PostOperationException('Only the thread author can create its first post.');
        }
        if ($this->posts->firstPost($threadId) !== null) {
            throw new PostOperationException('Thread already has a first post.');
        }

        $forum = $hierarchy->find($thread->forumNodeId());
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $thread->forumNodeId());
        $this->gate->require(PostPermission::Create->key(), $thread->forumNodeId());

        $settings = $forum?->forumSettings();
        if ($forum === null || $forum->type() !== ForumNodeType::Forum || $settings === null) {
            throw new PostOperationException('Thread forum is not available.');
        }

        $requiresApproval = $settings->requirePostApproval()
            || $thread->moderationState() !== ThreadModerationState::Visible;

        return $this->posts->create($threadId, $actor, $body, $requiresApproval, true, $now);
    }

    public function reply(EntityId $threadId, PostBody $body, DateTimeImmutable $now): Post
    {
        [$thread, $hierarchy] = $this->threadContext($threadId);
        $forum = $hierarchy->find($thread->forumNodeId());
        $settings = $forum?->forumSettings();
        if ($forum === null || $forum->type() !== ForumNodeType::Forum || $settings === null) {
            throw new PostOperationException('Thread forum is not available.');
        }

        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        $this->gate->require(PostPermission::Create->key(), $forum->id());
        if ($thread->moderationState() !== ThreadModerationState::Visible) {
            throw new PostOperationException('Replies are not available while the thread is awaiting moderation.');
        }
        if ($thread->isLocked()) {
            throw new PostOperationException('Thread is locked.');
        }
        if (!$settings->allowReplies()) {
            throw new PostOperationException('Replies are disabled for this forum.');
        }
        if (!$this->types->require($thread->typeKey())->allowsReplies()) {
            throw new PostOperationException('This thread type does not allow replies.');
        }
        $firstPost = $this->posts->firstPost($threadId);
        if ($firstPost === null || $firstPost->isDeleted()
            || $firstPost->moderationState() !== PostModerationState::Visible
        ) {
            throw new PostOperationException('Thread first post is not currently available.');
        }

        return $this->posts->create(
            $threadId,
            $this->gate->actorId(),
            $body,
            $settings->requirePostApproval(),
            false,
            $now,
        );
    }

    public function edit(EntityId $postId, PostBody $body, DateTimeImmutable $at): Post
    {
        [$post, $thread] = $this->postContext($postId);
        $this->requireOwnOrAny($post, PostPermission::EditOwn, PostPermission::EditAny, $thread->forumNodeId());
        if ($post->isDeleted()) {
            throw new PostOperationException('Deleted posts cannot be edited before restore.');
        }
        if ($post->edit($body, $this->gate->actorId(), $at)) {
            $this->posts->save($post);
        }
        return $post;
    }

    public function delete(EntityId $postId, DateTimeImmutable $at): Post
    {
        [$post, $thread] = $this->postContext($postId);
        if ($post->isFirstPost()) {
            $this->gate->require(PostPermission::DeleteAny->key(), $thread->forumNodeId());
        } else {
            $this->requireOwnOrAny($post, PostPermission::DeleteOwn, PostPermission::DeleteAny, $thread->forumNodeId());
        }
        if ($post->delete($this->gate->actorId(), $at)) {
            $this->posts->save($post);
        }
        return $post;
    }

    public function restore(EntityId $postId, DateTimeImmutable $at): Post
    {
        [$post, $thread] = $this->postContext($postId);
        $this->gate->require(PostPermission::Restore->key(), $thread->forumNodeId());
        if ($post->restore($this->gate->actorId(), $at)) {
            $this->posts->save($post);
        }
        return $post;
    }

    public function approve(EntityId $postId, DateTimeImmutable $at): Post
    {
        return $this->moderate($postId, $at, true);
    }

    public function reject(EntityId $postId, DateTimeImmutable $at): Post
    {
        return $this->moderate($postId, $at, false);
    }

    private function moderate(EntityId $postId, DateTimeImmutable $at, bool $approve): Post
    {
        [$post, $thread] = $this->postContext($postId);
        $this->gate->require(PostPermission::Moderate->key(), $thread->forumNodeId());
        $changed = $approve
            ? $post->approve($this->gate->actorId(), $at)
            : $post->reject($this->gate->actorId(), $at);
        if ($changed) {
            $this->posts->save($post);
        }
        return $post;
    }

    /** @return array{0:Thread,1:ForumNodeHierarchy} */
    private function threadContext(EntityId $threadId): array
    {
        $thread = $this->threads->find($threadId)
            ?? throw new PostOperationException('Thread is not available.');
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $forum = $hierarchy->find($thread->forumNodeId());
        if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
            throw new PostOperationException('Thread forum is not available.');
        }
        return [$thread, $hierarchy];
    }

    /** @return array{0:Post,1:Thread,2:ForumNodeHierarchy} */
    private function postContext(EntityId $postId): array
    {
        $post = $this->posts->find($postId)
            ?? throw new PostOperationException('Post is not available.');
        [$thread, $hierarchy] = $this->threadContext($post->threadId());
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $thread->forumNodeId());
        return [$post, $thread, $hierarchy];
    }

    private function requireOwnOrAny(
        Post $post,
        PostPermission $own,
        PostPermission $any,
        EntityId $forumNodeId,
    ): void {
        $author = $post->authorUserId();
        if ($author !== null && $author->equals($this->gate->actorId())) {
            try {
                $this->gate->require($own->key(), $forumNodeId);
                return;
            } catch (PermissionDeniedException) {
                // Explicit any-content permission may still authorize the actor.
            }
        }
        $this->gate->require($any->key(), $forumNodeId);
    }
}
