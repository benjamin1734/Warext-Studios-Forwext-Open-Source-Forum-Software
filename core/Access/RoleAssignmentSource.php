<?php

declare(strict_types=1);

namespace Forwext\Core\Access;

enum RoleAssignmentSource: string
{
    case Manual = 'manual';
    case System = 'system';
    case Promotion = 'promotion';
    case Upgrade = 'upgrade';
}
