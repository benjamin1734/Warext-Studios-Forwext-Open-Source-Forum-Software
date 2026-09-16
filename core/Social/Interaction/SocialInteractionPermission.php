<?php

declare(strict_types=1);

namespace Forwext\Core\Social\Interaction;

use Forwext\Core\Domain\Access\Permission\PermissionKey;

enum SocialInteractionPermission: string
{
    case React = 'forum.reaction.use';
    case Bookmark = 'forum.bookmark.use';
    case Follow = 'social.follow.use';
    case Ignore = 'social.ignore.use';

    public function key(): PermissionKey
    {
        return PermissionKey::fromString($this->value);
    }
}
