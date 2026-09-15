<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

use Forwext\Core\Domain\Access\Permission\PermissionKey;

enum PostPermission: string
{
    case Create = 'forum.post.create';
    case EditOwn = 'forum.post.edit_own';
    case EditAny = 'forum.post.edit_any';
    case DeleteOwn = 'forum.post.delete_own';
    case DeleteAny = 'forum.post.delete_any';
    case Restore = 'forum.post.restore';
    case Moderate = 'forum.post.moderate';

    public function key(): PermissionKey
    {
        return PermissionKey::fromString($this->value);
    }
}
