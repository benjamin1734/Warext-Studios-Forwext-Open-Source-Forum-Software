<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use Forwext\Core\Domain\Access\Permission\PermissionKey;

enum ThreadPermission: string
{
    case Create = 'forum.thread.create';
    case Lock = 'forum.thread.lock';
    case Sticky = 'forum.thread.sticky';
    case Feature = 'forum.thread.feature';
    case Moderate = 'forum.thread.moderate';

    public function key(): PermissionKey
    {
        return PermissionKey::fromString($this->value);
    }
}
