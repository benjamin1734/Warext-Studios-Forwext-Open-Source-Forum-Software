<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

enum PermissionValueType: string
{
    case Flag = 'flag';
    case Numeric = 'numeric';
}
