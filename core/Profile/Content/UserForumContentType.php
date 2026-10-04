<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Content;

enum UserForumContentType: string
{
    case Thread = 'thread';
    case Post = 'post';
}
