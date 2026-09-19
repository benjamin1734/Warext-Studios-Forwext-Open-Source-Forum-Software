<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

use DateTimeImmutable;
use Forwext\Core\Content\Pipeline\AbusePipelineAttributes;
use Forwext\Core\Content\Pipeline\ContentPipeline;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Content\Pipeline\ContentPipelinePersisted;
use Forwext\Core\Content\Pipeline\ContentPipelineRejectedException;
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
use Forwext\Core\Moderation\Abuse\AbuseContentContext;
use Forwext\Core\Moderation\Abuse\AbuseContext;
use Forwext\Core\Moderation\Abuse\AbuseDecision;
use Forwext\Core\Moderation\Abuse\AbuseEngine;

final readonly class PostService
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private ThreadTypeRegistry $types,
        private PostRepository $posts,
        private PermissionGate $gate,
        private ?AbuseEngine $abuse = null,
        private ?ContentPipeline $pipeline = null,
    ) {
    }

    public function createFirstPost(
        EntityId $threadId,
        PostBody $body,
        DateTimeImmutable $now,
        ?AbuseContext $abuseContext = null,
    ): Post
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

        $baseRequiresApproval = $settings->requirePostApproval()
            || $thread->moderationState() !== ThreadModerationState::Visible;
        if ($this->pipeline !== null) {
            return $this->createThroughPipeline(
                $threadId,
                $body,
                $baseRequiresApproval,
                true,
                $now,
                $abuseContext,
            );
        }

        [$decision, $context] = $this->evaluateCreate($body, $now, $abuseContext);
        $requiresApproval = $baseRequiresApproval || $decision->requiresReview();

        $post = $this->posts->create($threadId, $actor, $body, $requiresApproval, true, $now);
        if ($this->abuse !== null && $context !== null && $decision->requiresReview()) {
            $this->abuse->record($context, $decision, 'forum.post', $post->id(), $now);
        }
        return $post;
    }

    public function reply(
        EntityId $threadId,
        PostBody $body,
        DateTimeImmutable $now,
        ?AbuseContext $abuseContext = null,
    ): Post
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

        if ($this->pipeline !== null) {
            return $this->createThroughPipeline(
                $threadId,
                $body,
                $settings->requirePostApproval(),
                false,
                $now,
                $abuseContext,
            );
        }

        [$decision, $context] = $this->evaluateCreate($body, $now, $abuseContext);
        $post = $this->posts->create(
            $threadId,
            $this->gate->actorId(),
            $body,
            $settings->requirePostApproval() || $decision->requiresReview(),
            false,
            $now,
        );
        if ($this->abuse !== null && $context !== null && $decision->requiresReview()) {
            $this->abuse->record($context, $decision, 'forum.post', $post->id(), $now);
        }
        return $post;
    }

    public function edit(
        EntityId $postId,
        PostBody $body,
        DateTimeImmutable $at,
        ?AbuseContext $abuseContext = null,
    ): Post {
        [$post, $thread] = $this->postContext($postId);
        $this->requireOwnOrAny($post, PostPermission::EditOwn, PostPermission::EditAny, $thread->forumNodeId());
        if ($post->isDeleted()) {
            throw new PostOperationException('Deleted posts cannot be edited before restore.');
        }
        if ($post->body()->source() === $body->source()) {
            return $post;
        }

        if ($this->pipeline !== null) {
            $attributes = AbusePipelineAttributes::fromRequestContext(
                $abuseContext,
                \Forwext\Core\Moderation\Abuse\AbuseEventType::Post,
                $this->gate->actorId(),
            );
            $attributes['forum.node_id'] = $thread->forumNodeId()->value();
            try {
                $updated = $this->pipeline->execute(
                    new ContentPipelineContext(
                        $this->gate->actorId(),
                        'forum.post',
                        $body->source(),
                        100000,
                        $post->moderationState() === PostModerationState::Pending,
                        $attributes,
                    ),
                    $at,
                    function (ContentPipelineContext $context) use ($post, $at): ContentPipelinePersisted {
                        $changed = $post->edit(
                            PostBody::fromString($context->text),
                            $this->gate->actorId(),
                            $at,
                        );
                        if ($context->requiresReview
                            && $post->moderationState() === PostModerationState::Visible
                        ) {
                            $changed = $post->requestModeration($this->gate->actorId(), $at) || $changed;
                        }
                        if ($changed) {
                            $this->posts->save($post);
                        }
                        return new ContentPipelinePersisted($post, 'forum.post', $post->id());
                    },
                );
            } catch (ContentPipelineRejectedException $exception) {
                throw new PostOperationException(
                    'Post edit was blocked by content policy.',
                    previous: $exception,
                );
            }
            if (!$updated instanceof Post) {
                throw new PostOperationException('Post edit content pipeline returned an invalid result.');
            }
            return $updated;
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

    private function createThroughPipeline(
        EntityId $threadId,
        PostBody $body,
        bool $requiresApproval,
        bool $mustBeFirst,
        DateTimeImmutable $now,
        ?AbuseContext $abuseContext,
    ): Post {
        if ($this->pipeline === null) {
            throw new PostOperationException('Content pipeline is not configured.');
        }

        $attributes = AbusePipelineAttributes::fromRequestContext(
            $abuseContext,
            \Forwext\Core\Moderation\Abuse\AbuseEventType::Post,
            $this->gate->actorId(),
        );
        $thread = $this->threads->find($threadId)
            ?? throw new PostOperationException('Thread is not available.');
        $attributes['forum.node_id'] = $thread->forumNodeId()->value();

        try {
            $created = $this->pipeline->execute(
                new ContentPipelineContext(
                    $this->gate->actorId(),
                    'forum.post',
                    $body->source(),
                    100000,
                    $requiresApproval,
                    $attributes,
                ),
                $now,
                function (ContentPipelineContext $context) use ($threadId, $mustBeFirst, $now): ContentPipelinePersisted {
                    $post = $this->posts->create(
                        $threadId,
                        $this->gate->actorId(),
                        PostBody::fromString($context->text),
                        $context->requiresReview,
                        $mustBeFirst,
                        $now,
                    );
                    return new ContentPipelinePersisted($post, 'forum.post', $post->id());
                },
            );
        } catch (ContentPipelineRejectedException $exception) {
            throw new PostOperationException(
                'Post creation was blocked by content policy.',
                previous: $exception,
            );
        }

        if (!$created instanceof Post) {
            throw new PostOperationException('Post content pipeline returned an invalid result.');
        }
        return $created;
    }

    /** @return array{0:AbuseDecision,1:?AbuseContext} */
    private function evaluateCreate(
        PostBody $body,
        DateTimeImmutable $now,
        ?AbuseContext $requestContext,
    ): array {
        if ($this->abuse === null) {
            return [AbuseDecision::allow(), null];
        }
        $context = AbuseContentContext::post($this->gate->actorId(), $body->source(), $requestContext);
        $decision = $this->abuse->evaluate($context, $now);
        if ($decision->isRejected()) {
            $this->abuse->record($context, $decision, null, null, $now);
            throw new PostOperationException('Post creation was blocked by anti-abuse policy.');
        }
        return [$decision, $context];
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
