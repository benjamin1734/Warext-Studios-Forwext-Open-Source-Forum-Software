<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Discovery;

enum DiscoveryMode: string
{
    case New = 'new';
    case Unread = 'unread';
    case Trending = 'trending';
    case Featured = 'featured';
    case RecentActivity = 'recent_activity';
}
