<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

enum GiveawayState: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }
}
