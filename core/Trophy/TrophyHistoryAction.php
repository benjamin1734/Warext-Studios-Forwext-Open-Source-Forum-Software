<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

enum TrophyHistoryAction: string
{
    case Awarded = 'awarded';
    case Revoked = 'revoked';
}
