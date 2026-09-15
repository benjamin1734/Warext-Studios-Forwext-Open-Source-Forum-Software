<?php

declare(strict_types=1);
namespace Forwext\Core\Profile;
use Forwext\Core\Domain\Entity\EntityId;
final readonly class ProfileVisibilityPolicy
{
    public function canView(ProfileVisibility $visibility, EntityId $ownerId, ?EntityId $viewerId): bool
    {
        if ($viewerId !== null && $ownerId->equals($viewerId)) { return true; }
        return match ($visibility) {
            ProfileVisibility::Public => true,
            ProfileVisibility::Members => $viewerId !== null,
            ProfileVisibility::Private => false,
        };
    }
}
