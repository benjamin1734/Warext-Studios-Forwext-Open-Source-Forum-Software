<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

enum PermissionSubjectType: string
{
    case User = 'user';
    case Group = 'group';
    case Role = 'role';
}
