<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Widget;

enum WidgetOwnerType: string
{
    case Core = 'core';
    case Module = 'module';
    case Addon = 'addon';
}
