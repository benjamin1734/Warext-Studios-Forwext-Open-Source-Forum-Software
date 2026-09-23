<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

enum LayoutDevice: string
{
    case Desktop = 'desktop';
    case Tablet = 'tablet';
    case Mobile = 'mobile';
}
