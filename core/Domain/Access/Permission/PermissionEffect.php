<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

enum PermissionEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Inherit = 'inherit';
}
