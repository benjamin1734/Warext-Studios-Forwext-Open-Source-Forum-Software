<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Guide;

enum AppearancePreviewDevice: string
{
    case Desktop = 'desktop';
    case Tablet = 'tablet';
    case Mobile = 'mobile';
}
