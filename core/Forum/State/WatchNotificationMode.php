<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\State;

enum WatchNotificationMode: string
{
    case None = 'none';
    case InApp = 'in_app';
    case Email = 'email';
    case InAppEmail = 'in_app_email';
}
