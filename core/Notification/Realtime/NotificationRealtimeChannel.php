<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Realtime;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final class NotificationRealtimeChannel
{
    public static function forUser(EntityId $userId): string
    {
        UserId::assert($userId);
        return 'notification.user.' . $userId->value();
    }
}
