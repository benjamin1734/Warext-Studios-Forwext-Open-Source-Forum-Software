<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

enum ProfileActivityModerationState: string
{
    case Visible = 'visible';
    case Pending = 'pending';
    case Rejected = 'rejected';
}
