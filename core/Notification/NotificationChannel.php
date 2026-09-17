<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Push = 'push';
}
