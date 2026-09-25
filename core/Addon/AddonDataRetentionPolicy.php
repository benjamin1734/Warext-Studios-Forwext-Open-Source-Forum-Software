<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

enum AddonDataRetentionPolicy: string
{
    case RetainOnly = 'retain_only';
    case PurgeSupported = 'purge_supported';
}
