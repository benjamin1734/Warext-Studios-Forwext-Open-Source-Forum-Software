<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

use Forwext\Core\Domain\Entity\EntityId;

interface ProfileMusicPermissionResolver
{
    public function allows(EntityId $userId, ProfileMusicPermission $permission): bool;
}
