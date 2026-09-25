<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

enum AddonState: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Uninstalled = 'uninstalled';
}
