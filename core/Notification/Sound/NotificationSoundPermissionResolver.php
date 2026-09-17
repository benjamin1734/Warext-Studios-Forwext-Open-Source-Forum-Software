<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Sound;

use Forwext\Core\Domain\Entity\EntityId;

interface NotificationSoundPermissionResolver
{
    public function allows(EntityId $userId, NotificationSoundPermission $permission): bool;
}
