<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Social\Interaction\ReactionSummary;

interface ProfileActivityRepository
{
    public function settings(EntityId $profileOwnerUserId): ProfileActivitySettings;
    public function saveSettings(EntityId $profileOwnerUserId, ProfileActivitySettings $settings): void;

    public function createPost(EntityId $profileOwnerUserId, EntityId $authorUserId, ProfileActivityBody $body, DateTimeImmutable $now): ProfilePost;
    public function findPost(EntityId $profilePostId): ?ProfilePost;
    /** @return list<ProfilePost> */
    public function posts(EntityId $profileOwnerUserId, int $limit = 50, int $offset = 0): array;
    public function deletePost(EntityId $profilePostId, DateTimeImmutable $now): void;

    public function createComment(EntityId $profilePostId, EntityId $authorUserId, ProfileActivityBody $body, DateTimeImmutable $now): ProfileComment;
    public function findComment(EntityId $commentId): ?ProfileComment;
    /** @return list<ProfileComment> */
    public function comments(EntityId $profilePostId, int $limit = 100, int $offset = 0): array;
    public function deleteComment(EntityId $commentId, DateTimeImmutable $now): void;

    public function setReaction(EntityId $actorId, EntityId $profilePostId, string $reactionKey): void;
    public function removeReaction(EntityId $actorId, EntityId $profilePostId): void;
    public function reactionSummary(EntityId $profilePostId): ReactionSummary;
}
