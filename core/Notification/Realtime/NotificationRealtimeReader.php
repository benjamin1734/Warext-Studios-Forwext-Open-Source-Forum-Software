<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Realtime;

use Forwext\Core\Domain\Entity\EntityId;

interface NotificationRealtimeReader
{
    public function latestSequence(string $channel): int;

    /**
     * @param list<EntityId> $notificationIds
     * @return array<string, RealtimeNotificationSnapshot> keyed by notification id
     */
    public function visibleByIds(EntityId $recipientUserId, array $notificationIds): array;
}
