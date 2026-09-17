<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

enum ActivityFeedType: string
{
    case ThreadCreated = 'thread_created';
    case ForumPostCreated = 'forum_post_created';
    case ProfilePostCreated = 'profile_post_created';
    case ProfileCommentCreated = 'profile_comment_created';
    case ProfileReaction = 'profile_reaction';
}
