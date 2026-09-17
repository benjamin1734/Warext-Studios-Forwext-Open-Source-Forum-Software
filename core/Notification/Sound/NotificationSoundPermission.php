<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Sound;

enum NotificationSoundPermission: string
{
    case View = 'notification.alert.view';
    case Manage = 'notification.preference.manage';
}
