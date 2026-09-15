<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Appearance;

enum RoleAppearanceIcon: string
{
    case Shield = 'shield';
    case Star = 'star';
    case Crown = 'crown';
    case Hammer = 'hammer';
    case Check = 'check';
    case Diamond = 'diamond';
}
