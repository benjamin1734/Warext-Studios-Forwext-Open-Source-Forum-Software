<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access;

enum RoleKind: string
{
    case Custom = 'custom';
    case Staff = 'staff';
    case System = 'system';
}
