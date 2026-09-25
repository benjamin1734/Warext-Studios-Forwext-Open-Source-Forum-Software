<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

enum AddonUninstallMode: string
{
    case KeepData = 'keep_data';
    case DeleteData = 'delete_data';
}
