<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Post\PostPermission;
use Forwext\Core\Forum\Thread\ThreadPermission;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Throwable;

final readonly class ContentModerationService
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ContentModerationRepository $moderation,
        private PermissionGate $gate,
    ) {
    }

    public function moveThread(
        EntityId $threadId,
        EntityId $targetForumNodeId,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $thread = $this->requireThread($threadId);
        $this->requireForumPermission($thread->forumNodeId, ModerationPermission::MoveThread->key());
        $this->requireForumPermission($targetForumNodeId, ModerationPermission::MoveThread->key());
        $this->run(fn () => $this->moderation->moveThread(
            $threadId,
            $targetForumNodeId,
            $this->context($reason, $requestId, $at),
        ));
    }

    public function copyThread(
        EntityId $threadId,
        EntityId $targetForumNodeId,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): EntityId {
        $thread = $this->requireActiveThread($threadId);
        $this->requireForumPermission($thread->forumNodeId, ModerationPermission::CopyThread->key());
        $this->requireForumPermission($targetForumNodeId, ModerationPermission::CopyThread->key());

        return $this->run(fn (): EntityId => $this->moderation->copyThread(
            $threadId,
            $targetForumNodeId,
            $this->context($reason, $requestId, $at),
        ));
    }

    /** @param list<EntityId> $sourceThreadIds */
    public function mergeThreads(
        EntityId $destinationThreadId,
        array $sourceThreadIds,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $destination = $this->requireActiveThread($destinationThreadId);
        $forumIds = [$destination->forumNodeId->value() => $destination->forumNodeId];
        foreach ($sourceThreadIds as $sourceThreadId) {
            if (!$sourceThreadId instanceof EntityId) {
                throw new ModerationOperationException('Moderation thread targets are invalid.');
            }
            $source = $this->requireActiveThread($sourceThreadId);
            $forumIds[$source->forumNodeId->value()] = $source->forumNodeId;
        }
        foreach ($forumIds as $forumId) {
            $this->requireForumPermission($forumId, ModerationPermission::MergeThread->key());
        }

        $this->run(fn () => $this->moderation->mergeThreads(
            $destinationThreadId,
            $sourceThreadIds,
            $this->context($reason, $requestId, $at),
        ));
    }

    /** @param list<EntityId> $postIds */
    public function splitThread(
        EntityId $sourceThreadId,
        array $postIds,
        EntityId $targetForumNodeId,
        ThreadTitle $newTitle,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): EntityId {
        $source = $this->requireActiveThread($sourceThreadId);
        $this->requireForumPermission($source->forumNodeId, ModerationPermission::SplitThread->key());
        $this->requireForumPermission($targetForumNodeId, ModerationPermission::SplitThread->key());

        return $this->run(fn (): EntityId => $this->moderation->splitThread(
            $sourceThreadId,
            $postIds,
            $targetForumNodeId,
            $newTitle,
            $this->context($reason, $requestId, $at),
        ));
    }

    public function lockThread(
        EntityId $threadId,
        bool $locked,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $thread = $this->requireActiveThread($threadId);
        $this->requireForumPermission($thread->forumNodeId, ThreadPermission::Lock->key());
        $this->run(fn () => $this->moderation->setThreadLocked(
            $threadId,
            $locked,
            $this->context($reason, $requestId, $at),
        ));
    }

    public function stickThread(
        EntityId $threadId,
        bool $sticky,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $thread = $this->requireActiveThread($threadId);
        $this->requireForumPermission($thread->forumNodeId, ThreadPermission::Sticky->key());
        $this->run(fn () => $this->moderation->setThreadSticky(
            $threadId,
            $sticky,
            $this->context($reason, $requestId, $at),
        ));
    }

    public function approveThread(
        EntityId $threadId,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $thread = $this->requireActiveThread($threadId);
        $this->requireForumPermission($thread->forumNodeId, ThreadPermission::Moderate->key());
        $this->run(fn () => $this->moderation->approveThread(
            $threadId,
            $this->context($reason, $requestId, $at),
        ));
    }

    public function deleteThread(
        EntityId $threadId,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $thread = $this->requireThread($threadId);
        $this->requireForumPermission($thread->forumNodeId, ModerationPermission::DeleteThread->key());
        $this->run(fn () => $this->moderation->setThreadDeleted(
            $threadId,
            true,
            $this->context($reason, $requestId, $at),
        ));
    }

    public function restoreThread(
        EntityId $threadId,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $thread = $this->requireThread($threadId);
        $this->requireForumPermission($thread->forumNodeId, ModerationPermission::RestoreThread->key());
        $this->run(fn () => $this->moderation->setThreadDeleted(
            $threadId,
            false,
            $this->context($reason, $requestId, $at),
        ));
    }

    public function approvePost(
        EntityId $postId,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $post = $this->requirePost($postId);
        $this->requireForumPermission($post->forumNodeId, PostPermission::Moderate->key());
        $this->run(fn () => $this->moderation->approvePost(
            $postId,
            $this->context($reason, $requestId, $at),
        ));
    }

    public function deletePost(
        EntityId $postId,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $post = $this->requirePost($postId);
        $this->requireForumPermission($post->forumNodeId, PostPermission::DeleteAny->key());
        $this->run(fn () => $this->moderation->setPostDeleted(
            $postId,
            true,
            $this->context($reason, $requestId, $at),
        ));
    }

    public function restorePost(
        EntityId $postId,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $post = $this->requirePost($postId);
        $this->requireForumPermission($post->forumNodeId, PostPermission::Restore->key());
        $this->run(fn () => $this->moderation->setPostDeleted(
            $postId,
            false,
            $this->context($reason, $requestId, $at),
        ));
    }

    /** @param list<EntityId> $threadIds */
    public function bulkThreads(
        BulkThreadAction $action,
        array $threadIds,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $permission = $this->threadBulkPermission($action);
        foreach ($this->threadForums($threadIds) as $forumId) {
            $this->requireForumPermission($forumId, ModerationPermission::Bulk->key());
            $this->requireForumPermission($forumId, $permission);
        }
        $this->run(fn () => $this->moderation->bulkThreads(
            $action,
            $threadIds,
            $this->context($reason, $requestId, $at),
        ));
    }

    /** @param list<EntityId> $postIds */
    public function bulkPosts(
        BulkPostAction $action,
        array $postIds,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $permission = match ($action) {
            BulkPostAction::Approve, BulkPostAction::Reject => PostPermission::Moderate->key(),
            BulkPostAction::Delete => PostPermission::DeleteAny->key(),
            BulkPostAction::Restore => PostPermission::Restore->key(),
        };
        foreach ($this->postForums($postIds) as $forumId) {
            $this->requireForumPermission($forumId, ModerationPermission::Bulk->key());
            $this->requireForumPermission($forumId, $permission);
        }
        $this->run(fn () => $this->moderation->bulkPosts(
            $action,
            $postIds,
            $this->context($reason, $requestId, $at),
        ));
    }

    private function requireThread(EntityId $threadId): ModerationThreadRecord
    {
        return $this->moderation->thread($threadId)
            ?? throw new ModerationOperationException('Moderation thread is not available.');
    }

    private function requireActiveThread(EntityId $threadId): ModerationThreadRecord
    {
        $thread = $this->requireThread($threadId);
        if (!$thread->isActive()) {
            throw new ModerationOperationException('Moderation operation requires an active thread.');
        }
        return $thread;
    }

    private function requirePost(EntityId $postId): ModerationPostRecord
    {
        return $this->moderation->post($postId)
            ?? throw new ModerationOperationException('Moderation post is not available.');
    }

    private function requireForumPermission(EntityId $forumNodeId, PermissionKey $permission): void
    {
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $forum = $hierarchy->find($forumNodeId);
        if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
            throw new ModerationOperationException('Moderation forum is not available.');
        }
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forum->id());
        $this->gate->require($permission, $forum->id());
    }

    /** @param list<EntityId> $threadIds @return list<EntityId> */
    private function threadForums(array $threadIds): array
    {
        $forums = [];
        foreach ($threadIds as $threadId) {
            if (!$threadId instanceof EntityId) {
                throw new ModerationOperationException('Moderation thread targets are invalid.');
            }
            $thread = $this->requireThread($threadId);
            $forums[$thread->forumNodeId->value()] = $thread->forumNodeId;
        }
        return array_values($forums);
    }

    /** @param list<EntityId> $postIds @return list<EntityId> */
    private function postForums(array $postIds): array
    {
        $forums = [];
        foreach ($postIds as $postId) {
            if (!$postId instanceof EntityId) {
                throw new ModerationOperationException('Moderation post targets are invalid.');
            }
            $post = $this->requirePost($postId);
            $forums[$post->forumNodeId->value()] = $post->forumNodeId;
        }
        return array_values($forums);
    }

    private function threadBulkPermission(BulkThreadAction $action): PermissionKey
    {
        return match ($action) {
            BulkThreadAction::Lock, BulkThreadAction::Unlock => ThreadPermission::Lock->key(),
            BulkThreadAction::Sticky, BulkThreadAction::Unsticky => ThreadPermission::Sticky->key(),
            BulkThreadAction::Approve, BulkThreadAction::Reject => ThreadPermission::Moderate->key(),
            BulkThreadAction::Delete => ModerationPermission::DeleteThread->key(),
            BulkThreadAction::Restore => ModerationPermission::RestoreThread->key(),
        };
    }

    private function context(
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): ModerationAuditContext {
        return new ModerationAuditContext($this->gate->actorId(), $reason, $requestId, $at);
    }

    /** @template T @param callable():T $operation @return T */
    private function run(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ModerationOperationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ModerationOperationException('Moderation operation failed.', previous: $exception);
        }
    }
}
