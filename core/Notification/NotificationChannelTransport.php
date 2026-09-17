<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

interface NotificationChannelTransport
{
    public function channel(): NotificationChannel;

    public function deliver(Notification $notification): NotificationDeliveryResult;
}
