<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Responsive;

enum ResponsiveLayout: string
{
    case Auto = 'auto';
    case Stack = 'stack';
    case Row = 'row';
    case Grid = 'grid';
}
