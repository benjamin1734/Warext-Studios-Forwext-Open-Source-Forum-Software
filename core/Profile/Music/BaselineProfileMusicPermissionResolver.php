<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class BaselineProfileMusicPermissionResolver implements ProfileMusicPermissionResolver
{
    public function __construct(
        private bool $use = true,
        private bool $upload = true,
        private bool $external = false,
        private bool $autoplay = true,
        private bool $moderate = false,
    ) {
    }

    public function allows(EntityId $userId, ProfileMusicPermission $permission): bool
    {
        UserId::assert($userId);

        return match ($permission) {
            ProfileMusicPermission::Use => $this->use,
            ProfileMusicPermission::Upload => $this->upload,
            ProfileMusicPermission::External => $this->external,
            ProfileMusicPermission::Autoplay => $this->autoplay,
            ProfileMusicPermission::Moderate => $this->moderate,
        };
    }
}
