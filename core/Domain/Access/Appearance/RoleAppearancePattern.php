<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Appearance;

enum RoleAppearancePattern: string
{
    case None = 'none';
    case Stripes = 'stripes';
    case Dots = 'dots';
    case Grid = 'grid';
    case Diagonal = 'diagonal';
}
