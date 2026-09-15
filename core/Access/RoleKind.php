<?php

declare(strict_types=1);

namespace Forwext\Core\Access;

enum RoleKind: string
{
    case Standard = 'standard';
    case Staff = 'staff';
    case System = 'system';

    public function isPrivileged(): bool
    {
        return $this === self::Staff || $this === self::System;
    }
}
