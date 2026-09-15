<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

enum ForumNodeType: string
{
    case Category = 'category';
    case Forum = 'forum';
    case Page = 'page';
    case Link = 'link';

    public function canContainChildren(): bool
    {
        return $this === self::Category || $this === self::Forum;
    }
}
