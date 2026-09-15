<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

enum ThreadModerationState: string
{
    case Visible = 'visible';
    case Pending = 'pending';
    case Rejected = 'rejected';
}
