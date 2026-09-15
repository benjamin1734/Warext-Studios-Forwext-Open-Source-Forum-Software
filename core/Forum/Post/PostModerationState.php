<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

enum PostModerationState: string
{
    case Visible = 'visible';
    case Pending = 'pending';
    case Rejected = 'rejected';
}
