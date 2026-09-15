<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Appearance;

enum RoleDisplayContext: string
{
    case Profile = 'profile';
    case Post = 'post';
}
