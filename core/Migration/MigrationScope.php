<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

enum MigrationScope: string
{
    case Core = 'core';
    case Module = 'module';
    case Addon = 'addon';
}
