<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Background;

enum BackgroundKind: string
{
    case Solid = 'solid';
    case Gradient = 'gradient';
    case Image = 'image';
    case Pattern = 'pattern';
}
