<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use Forwext\Core\Domain\Access\Permission\PermissionKey;

enum ModerationPermission: string
{
    case MoveThread = 'forum.thread.move';
    case CopyThread = 'forum.thread.copy';
    case MergeThread = 'forum.thread.merge';
    case SplitThread = 'forum.thread.split';
    case DeleteThread = 'forum.thread.delete';
    case RestoreThread = 'forum.thread.restore';
    case Bulk = 'forum.moderation.bulk';

    public function key(): PermissionKey
    {
        return PermissionKey::fromString($this->value);
    }
}
