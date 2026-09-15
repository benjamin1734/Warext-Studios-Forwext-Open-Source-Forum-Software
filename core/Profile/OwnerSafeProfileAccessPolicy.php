<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class OwnerSafeProfileAccessPolicy implements ProfileAccessPolicy
{
    public function canViewProfile(UserProfile $profile, ?EntityId $viewerId): bool
    {
        return $this->allows($profile->profileVisibility, $profile->userId, $viewerId);
    }

    public function canViewSection(
        UserProfile $profile,
        ProfileVisibility $sectionVisibility,
        ?EntityId $viewerId,
    ): bool {
        if (!$this->canViewProfile($profile, $viewerId)) {
            return false;
        }

        return $this->allows($sectionVisibility, $profile->userId, $viewerId);
    }

    public function canEdit(EntityId $profileOwnerId, ?EntityId $viewerId): bool
    {
        UserId::assert($profileOwnerId);

        if ($viewerId === null) {
            return false;
        }

        UserId::assert($viewerId);
        return $profileOwnerId->equals($viewerId);
    }

    private function allows(
        ProfileVisibility $visibility,
        EntityId $ownerId,
        ?EntityId $viewerId,
    ): bool {
        UserId::assert($ownerId);

        if ($viewerId !== null) {
            UserId::assert($viewerId);
            if ($ownerId->equals($viewerId)) {
                return true;
            }
        }

        return match ($visibility) {
            ProfileVisibility::Public => true,
            ProfileVisibility::Members => $viewerId !== null,
            ProfileVisibility::Private => false,
        };
    }
}
