<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

enum AttachmentState: string
{
    case Temporary = 'temporary';
    case Attached = 'attached';
}
