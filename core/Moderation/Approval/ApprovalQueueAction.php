<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Approval;

enum ApprovalQueueAction: string
{
    case Approve = 'approve';
    case Reject = 'reject';
}
