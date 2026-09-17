<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

final readonly class NotificationDelivery
{
    public function __construct(
        public Notification $notification,
        public NotificationChannel $channel,
        public int $attempts,
    ) {
    }
}
