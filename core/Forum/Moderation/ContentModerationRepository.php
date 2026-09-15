<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Thread\ThreadTitle;

interface ContentModerationRepository
{
    public function thread(EntityId $threadId): ?ModerationThreadRecord;

    public function post(EntityId $postId): ?ModerationPostRecord;

    public function moveThread(
        EntityId $threadId,
        EntityId $targetForumNodeId,
        ModerationAuditContext $context,
    ): void;

    public function copyThread(
        EntityId $threadId,
        EntityId $targetForumNodeId,
        ModerationAuditContext $context,
    ): EntityId;

    /** @param list<EntityId> $sourceThreadIds */
    public function mergeThreads(
        EntityId $destinationThreadId,
        array $sourceThreadIds,
        ModerationAuditContext $context,
    ): void;

    /** @param list<EntityId> $postIds */
    public function splitThread(
        EntityId $sourceThreadId,
        array $postIds,
        EntityId $targetForumNodeId,
        ThreadTitle $newTitle,
        ModerationAuditContext $context,
    ): EntityId;

    public function setThreadLocked(EntityId $threadId, bool $locked, ModerationAuditContext $context): void;

    public function setThreadSticky(EntityId $threadId, bool $sticky, ModerationAuditContext $context): void;

    public function approveThread(EntityId $threadId, ModerationAuditContext $context): void;

    public function setThreadDeleted(EntityId $threadId, bool $deleted, ModerationAuditContext $context): void;

    public function approvePost(EntityId $postId, ModerationAuditContext $context): void;

    public function setPostDeleted(EntityId $postId, bool $deleted, ModerationAuditContext $context): void;

    /** @param list<EntityId> $threadIds */
    public function bulkThreads(BulkThreadAction $action, array $threadIds, ModerationAuditContext $context): void;

    /** @param list<EntityId> $postIds */
    public function bulkPosts(BulkPostAction $action, array $postIds, ModerationAuditContext $context): void;
}
