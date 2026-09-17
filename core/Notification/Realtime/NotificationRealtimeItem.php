<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Realtime;

final readonly class NotificationRealtimeItem
{
    public function __construct(
        public int $sequence,
        public RealtimeNotificationSnapshot $notification,
    ) {
        if ($sequence < 1) {
            throw new NotificationRealtimeException('Notification realtime sequence must be positive.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'notification' => $this->notification->toArray(),
        ];
    }
}
