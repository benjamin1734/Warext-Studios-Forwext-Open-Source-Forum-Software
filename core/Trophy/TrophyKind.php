<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

enum TrophyKind: string
{
    case Trophy = 'trophy';
    case Badge = 'badge';
    case Achievement = 'achievement';
}
