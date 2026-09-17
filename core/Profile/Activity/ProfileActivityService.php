<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Social\Interaction\ReactionSummary;
use Forwext\Core\Social\Interaction\SocialInteractionRepository;

final readonly class ProfileActivityService
{
    public function __construct(
        private ProfileActivityRepository $profiles,
        private SocialInteractionRepository $social,
        private UserRepository $users,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function settings(EntityId $viewerId, EntityId $profileOwnerId): ProfileActivitySettings
    {
        $this->requireProfileView($viewerId, $profileOwnerId);
        return $this->profiles->settings($profileOwnerId);
    }

    public function updateSettings(EntityId $actorId, ProfileActivitySettings $settings): void
    {
        $this->requireActiveUser($actorId);
        $this->profiles->saveSettings($actorId, $settings);
    }

    /** @return list<ProfilePost> */
    public function posts(EntityId $viewerId, EntityId $profileOwnerId, int $limit = 50, int $offset = 0): array
    {
        $this->requireProfileView($viewerId, $profileOwnerId);
        return $this->profiles->posts($profileOwnerId, $limit, $offset);
    }

    public function createPost(EntityId $actorId, EntityId $profileOwnerId, ProfileActivityBody $body, DateTimeImmutable $now): ProfilePost
    {
        $this->requireActiveUser($actorId);
        $this->requireActiveUser($profileOwnerId);
        $gate = $this->gate($actorId);
        $gate->require(ProfileActivityPermission::Create->key());
        if (!$this->canWriteToProfile($actorId, $profileOwnerId)) {
            throw new ProfileActivityException('Profile posting is unavailable.');
        }
        return $this->profiles->createPost($profileOwnerId, $actorId, $body, $now);
    }

    /** @return list<ProfileComment> */
    public function comments(EntityId $viewerId, EntityId $profilePostId, int $limit = 100, int $offset = 0): array
    {
        $post = $this->requireVisiblePost($viewerId, $profilePostId);
        $this->requireProfileView($viewerId, $post->profileOwnerUserId);
        return $this->profiles->comments($profilePostId, $limit, $offset);
    }

    public function comment(EntityId $actorId, EntityId $profilePostId, ProfileActivityBody $body, DateTimeImmutable $now): ProfileComment
    {
        $this->requireActiveUser($actorId);
        $post = $this->requireVisiblePost($actorId, $profilePostId);
        $this->gate($actorId)->require(ProfileActivityPermission::Comment->key());
        if ($this->blockedEitherWay($actorId, $post->profileOwnerUserId)) {
            throw new ProfileActivityException('Profile commenting is unavailable.');
        }
        return $this->profiles->createComment($profilePostId, $actorId, $body, $now);
    }

    public function react(EntityId $actorId, EntityId $profilePostId, string $reactionKey): ReactionSummary
    {
        $this->requireActiveUser($actorId);
        $post = $this->requireVisiblePost($actorId, $profilePostId);
        $this->gate($actorId)->require(ProfileActivityPermission::React->key());
        if ($this->blockedEitherWay($actorId, $post->profileOwnerUserId)) {
            throw new ProfileActivityException('Profile reaction is unavailable.');
        }
        if ($post->authorUserId?->value() === $actorId->value()) {
            throw new ProfileActivityException('Users cannot react to their own profile posts.');
        }
        $type = $this->social->reactionType($reactionKey);
        if ($type === null) throw new ProfileActivityException('Reaction type is unavailable.');
        $this->profiles->setReaction($actorId, $profilePostId, $type->key);
        return $this->profiles->reactionSummary($profilePostId);
    }

    public function removeReaction(EntityId $actorId, EntityId $profilePostId): ReactionSummary
    {
        $post = $this->requireVisiblePost($actorId, $profilePostId);
        $this->gate($actorId)->require(ProfileActivityPermission::React->key());
        if ($this->blockedEitherWay($actorId, $post->profileOwnerUserId)) {
            throw new ProfileActivityException('Profile reaction is unavailable.');
        }
        $this->profiles->removeReaction($actorId, $profilePostId);
        return $this->profiles->reactionSummary($profilePostId);
    }

    public function reactionSummary(EntityId $viewerId, EntityId $profilePostId): ReactionSummary
    {
        $this->requireVisiblePost($viewerId, $profilePostId);
        return $this->profiles->reactionSummary($profilePostId);
    }

    public function deletePost(EntityId $actorId, EntityId $profilePostId, DateTimeImmutable $now): void
    {
        $post = $this->profiles->findPost($profilePostId);
        if ($post === null || $post->isDeleted()) throw new ProfileActivityException('Profile post is unavailable.');
        if (!$this->canManage($actorId, $post->profileOwnerUserId, $post->authorUserId)) {
            throw new ProfileActivityException('Profile post cannot be managed by this actor.');
        }
        $this->profiles->deletePost($profilePostId, $now);
    }

    public function deleteComment(EntityId $actorId, EntityId $commentId, DateTimeImmutable $now): void
    {
        $comment = $this->profiles->findComment($commentId);
        if ($comment === null || $comment->isDeleted()) throw new ProfileActivityException('Profile comment is unavailable.');
        $post = $this->profiles->findPost($comment->profilePostId);
        if ($post === null) throw new ProfileActivityException('Profile comment is unavailable.');
        if (!$this->canManage($actorId, $post->profileOwnerUserId, $comment->authorUserId)) {
            throw new ProfileActivityException('Profile comment cannot be managed by this actor.');
        }
        $this->profiles->deleteComment($commentId, $now);
    }

    public function canViewProfile(EntityId $viewerId, EntityId $profileOwnerId): bool
    {
        try {
            $this->requireProfileView($viewerId, $profileOwnerId);
            return true;
        } catch (ProfileActivityException|\Forwext\Core\Domain\Access\Permission\PermissionDeniedException) {
            return false;
        }
    }

    private function requireProfileView(EntityId $viewerId, EntityId $profileOwnerId): void
    {
        $this->requireActiveUser($profileOwnerId);
        $gate = $this->gate($viewerId);
        $gate->require(ProfileActivityPermission::View->key());
        if ($viewerId->value() === $profileOwnerId->value() || $gate->allows(ProfileActivityPermission::Moderate->key())) return;
        $scope = $this->profiles->settings($profileOwnerId)->viewScope;
        if (!$this->scopeAllows($scope, $viewerId, $profileOwnerId)) {
            throw new ProfileActivityException('Profile activity is unavailable.');
        }
    }

    private function canWriteToProfile(EntityId $actorId, EntityId $profileOwnerId): bool
    {
        if ($actorId->value() === $profileOwnerId->value()) return true;
        if ($this->blockedEitherWay($actorId, $profileOwnerId)) return false;
        return $this->scopeAllows($this->profiles->settings($profileOwnerId)->postScope, $actorId, $profileOwnerId);
    }

    private function scopeAllows(ProfileActivityScope $scope, EntityId $viewerId, EntityId $profileOwnerId): bool
    {
        return match ($scope) {
            ProfileActivityScope::Everyone => true,
            ProfileActivityScope::Followers => $this->social->isFollowing($viewerId, $profileOwnerId),
            ProfileActivityScope::OwnerOnly => false,
        };
    }

    private function blockedEitherWay(EntityId $actorId, EntityId $profileOwnerId): bool
    {
        if ($actorId->value() === $profileOwnerId->value()) return false;
        return $this->social->isIgnoring($actorId, $profileOwnerId)
            || $this->social->isIgnoring($profileOwnerId, $actorId);
    }

    private function requireVisiblePost(EntityId $viewerId, EntityId $profilePostId): ProfilePost
    {
        $post = $this->profiles->findPost($profilePostId);
        if ($post === null || $post->isDeleted() || $post->moderationState !== ProfileActivityModerationState::Visible) {
            throw new ProfileActivityException('Profile post is unavailable.');
        }
        $this->requireProfileView($viewerId, $post->profileOwnerUserId);
        return $post;
    }

    private function canManage(EntityId $actorId, EntityId $profileOwnerId, ?EntityId $authorId): bool
    {
        if ($actorId->value() === $profileOwnerId->value() || $authorId?->value() === $actorId->value()) return true;
        return $this->gate($actorId)->allows(ProfileActivityPermission::Moderate->key());
    }

    private function requireActiveUser(EntityId $userId): void
    {
        $user = $this->users->find($userId);
        if ($user === null || $user->status() !== UserStatus::Active) throw new ProfileActivityException('User is unavailable.');
    }

    private function gate(EntityId $actorId): PermissionGate { return new PermissionGate($this->authorizer, $actorId); }
}
