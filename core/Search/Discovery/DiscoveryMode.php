<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Discovery;

enum DiscoveryMode: string
{
    case New = 'new';
    case Unread = 'unread';
    case Trending = 'trending';
    case Featured = 'featured';
    case NoReplies = 'no_replies';
    case StartedByViewer = 'started_by_viewer';
    case ParticipatedByViewer = 'participated_by_viewer';
    case RecentActivity = 'recent_activity';
}
