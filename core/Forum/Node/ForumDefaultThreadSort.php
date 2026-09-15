<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

enum ForumDefaultThreadSort: string
{
    case LastPost = 'last_post';
    case Created = 'created';
    case Title = 'title';
}
