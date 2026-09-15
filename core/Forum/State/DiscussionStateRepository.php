<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\State;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface DiscussionStateRepository
{
    public function draft(EntityId $userId, DraftTargetType $targetType, EntityId $targetId): ?ContentDraft;

    public function saveDraft(
        EntityId $userId,
        DraftTargetType $targetType,
        EntityId $targetId,
        ?string $titleSource,
        string $bodySource,
        int $expectedRevision,
        DateTimeImmutable $at,
    ): ContentDraft;

    public function deleteDraft(EntityId $userId, DraftTargetType $targetType, EntityId $targetId): void;

    public function markThreadRead(EntityId $userId, EntityId $threadId, int $postPosition, DateTimeImmutable $at): void;

    public function markForumRead(EntityId $userId, EntityId $forumNodeId, DateTimeImmutable $at): void;

    public function isThreadUnread(EntityId $userId, EntityId $threadId): bool;

    public function watchThread(EntityId $userId, EntityId $threadId, WatchNotificationMode $mode, DateTimeImmutable $at): void;

    public function unwatchThread(EntityId $userId, EntityId $threadId): void;

    public function threadWatch(EntityId $userId, EntityId $threadId): ?WatchNotificationMode;

    public function watchForum(EntityId $userId, EntityId $forumNodeId, WatchNotificationMode $mode, DateTimeImmutable $at): void;

    public function unwatchForum(EntityId $userId, EntityId $forumNodeId): void;

    public function forumWatch(EntityId $userId, EntityId $forumNodeId): ?WatchNotificationMode;

    public function subscriptionPreferences(EntityId $userId): SubscriptionPreferences;

    public function saveSubscriptionPreferences(
        EntityId $userId,
        SubscriptionPreferences $preferences,
        DateTimeImmutable $at,
    ): void;
}
