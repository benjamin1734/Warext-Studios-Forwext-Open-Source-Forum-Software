<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

enum BulkPostAction: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Delete = 'delete';
    case Restore = 'restore';
}
