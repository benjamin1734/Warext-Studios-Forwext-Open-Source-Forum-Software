<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

enum AddonDataState: string
{
    case Retained = 'retained';
    case Purged = 'purged';
}
