<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use Forwext\Core\Domain\Access\Permission\PermissionKey;

enum AttachmentPermission: string
{
    case Upload = 'forum.attachment.upload';
    case Download = 'forum.attachment.download';
    case ManageAny = 'forum.attachment.manage_any';

    public function key(): PermissionKey
    {
        return PermissionKey::fromString($this->value);
    }
}
