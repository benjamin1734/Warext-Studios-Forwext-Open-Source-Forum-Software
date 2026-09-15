<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class ProfileService
{
    public function __construct(
        private ProfileStore $store,
        private ProfileAccessPolicy $accessPolicy = new OwnerSafeProfileAccessPolicy(),
    ) {
    }

    public function getOrDefault(EntityId $userId, DateTimeImmutable $now): UserProfile
    {
        UserId::assert($userId);

        return $this->store->find($userId) ?? new UserProfile(
            $userId,
            '',
            null,
            null,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            [],
            self::defaultTabs(),
            UserProfile::utc($now),
        );
    }

    public function visibleProfile(
        EntityId $userId,
        ?EntityId $viewerId,
        DateTimeImmutable $now,
    ): ?UserProfile {
        $profile = $this->getOrDefault($userId, $now);
        return $this->accessPolicy->canViewProfile($profile, $viewerId) ? $profile : null;
    }

    /**
     * @param list<SocialLink> $links
     * @param list<ProfileTab> $tabs
     */
    public function update(
        EntityId $userId,
        EntityId $actorId,
        string $about,
        ProfileVisibility $profileVisibility,
        ProfileVisibility $aboutVisibility,
        ProfileVisibility $socialVisibility,
        ProfileVisibility $mediaVisibility,
        array $links,
        array $tabs,
        DateTimeImmutable $now,
    ): UserProfile {
        UserId::assert($userId);
        UserId::assert($actorId);

        if (!$this->accessPolicy->canEdit($userId, $actorId)) {
            throw new ProfileException('Profile update is not permitted.');
        }

        $current = $this->getOrDefault($userId, $now);
        $profile = new UserProfile(
            $userId,
            trim($about),
            $current->avatarPath,
            $current->bannerPath,
            $profileVisibility,
            $aboutVisibility,
            $socialVisibility,
            $mediaVisibility,
            $links,
            $tabs,
            UserProfile::utc($now),
        );

        $this->store->save($profile);
        return $profile;
    }

    /** @return list<ProfileTab> */
    public static function defaultTabs(): array
    {
        return [
            new ProfileTab('overview', true, ProfileVisibility::Public, 0),
            new ProfileTab('activity', true, ProfileVisibility::Public, 10),
            new ProfileTab('about', true, ProfileVisibility::Public, 20),
        ];
    }
}
