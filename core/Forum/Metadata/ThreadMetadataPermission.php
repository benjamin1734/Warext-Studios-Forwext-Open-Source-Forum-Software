<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use Forwext\Core\Domain\Access\Permission\PermissionKey;

enum ThreadMetadataPermission: string
{
    case EditOwn = 'forum.thread.edit_own';
    case EditAny = 'forum.thread.edit_any';

    public function key(): PermissionKey
    {
        return PermissionKey::fromString($this->value);
    }
}
