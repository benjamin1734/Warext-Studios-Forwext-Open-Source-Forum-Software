<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Responsive;

enum ResponsiveVisibility: string
{
    case Inherit = 'inherit';
    case Visible = 'visible';
    case Hidden = 'hidden';
}
