<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

enum ForumNodeVisibility: string
{
    case Listed = 'listed';
    case Unlisted = 'unlisted';
    case Disabled = 'disabled';
}
