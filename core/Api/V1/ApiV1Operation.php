<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

enum ApiV1Operation: string
{
    case ServiceDocument = 'service_document';
    case UserShow = 'user_show';
    case ForumIndex = 'forum_index';
    case ForumShow = 'forum_show';
    case ForumThreads = 'forum_threads';
    case ThreadShow = 'thread_show';
    case ThreadPosts = 'thread_posts';
    case PostShow = 'post_show';
}
