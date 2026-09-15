<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Appearance;

enum RoleAppearanceAnimation: string
{
    case None = 'none';
    case Pulse = 'pulse';
    case Glow = 'glow';
    case Shimmer = 'shimmer';
    case Rainbow = 'rainbow';
}
