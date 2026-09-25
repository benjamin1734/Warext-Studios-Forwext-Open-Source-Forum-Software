<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

enum ApiV1Resource: string
{
    case Users = 'users';
    case Forums = 'forums';
    case Threads = 'threads';
    case Posts = 'posts';
    case Conversations = 'conversations';
    case Notifications = 'notifications';
    case Modules = 'modules';
    case Marketplace = 'marketplace';
    case Support = 'support';
}
