<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Sound;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class EngineNotificationSoundPermissionResolver implements NotificationSoundPermissionResolver
{
    public function __construct(private PermissionAuthorizer $authorizer)
    {
    }

    public function allows(EntityId $userId, NotificationSoundPermission $permission): bool
    {
        return $this->authorizer->allows($userId, PermissionKey::fromString($permission->value));
    }
}
