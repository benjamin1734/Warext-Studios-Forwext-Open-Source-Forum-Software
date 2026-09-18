<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

enum BulkThreadAction: string
{
    case Lock = 'lock';
    case Unlock = 'unlock';
    case Sticky = 'sticky';
    case Unsticky = 'unsticky';
    case Approve = 'approve';
    case Reject = 'reject';
    case Delete = 'delete';
    case Restore = 'restore';
}
