<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Appearance;

use Forwext\Core\Domain\Access\Role;

final readonly class RolePresentation
{
    public function __construct(
        public Role $role,
        public RoleAppearance $appearance,
    ) {
    }
}
