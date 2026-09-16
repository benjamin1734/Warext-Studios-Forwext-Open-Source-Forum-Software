<?php

declare(strict_types=1);

namespace Forwext\Core\Social\Interaction;

use Forwext\Core\Domain\Entity\EntityId;

interface SocialInteractionRepository
{
    public function reactionType(string $key): ?ReactionType;

    public function setReaction(EntityId $actorId, EntityId $postId, string $reactionKey): void;

    public function removeReaction(EntityId $actorId, EntityId $postId): void;

    public function reactionSummary(EntityId $postId): ReactionSummary;

    public function saveBookmark(EntityId $actorId, EntityId $postId, ?string $note): void;

    public function removeBookmark(EntityId $actorId, EntityId $postId): void;

    /** @return list<BookmarkEntry> */
    public function bookmarks(EntityId $actorId, int $limit = 50, int $offset = 0): array;

    public function follow(EntityId $actorId, EntityId $targetId): void;

    public function unfollow(EntityId $actorId, EntityId $targetId): void;

    public function ignore(EntityId $actorId, EntityId $targetId): void;

    public function unignore(EntityId $actorId, EntityId $targetId): void;

    public function isFollowing(EntityId $actorId, EntityId $targetId): bool;

    public function isIgnoring(EntityId $actorId, EntityId $targetId): bool;

    /** @return list<EntityId> */
    public function ignoredUserIds(EntityId $actorId): array;
}
