<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

enum OversightReviewStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
