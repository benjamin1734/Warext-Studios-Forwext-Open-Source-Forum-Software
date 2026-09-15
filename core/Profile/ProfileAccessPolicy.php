<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

use Forwext\Core\Domain\Entity\EntityId;

interface ProfileAccessPolicy
{
    public function canViewProfile(UserProfile $profile, ?EntityId $viewerId): bool;

    public function canViewSection(
        UserProfile $profile,
        ProfileVisibility $sectionVisibility,
        ?EntityId $viewerId,
    ): bool;

    public function canEdit(EntityId $profileOwnerId, ?EntityId $viewerId): bool;
}
