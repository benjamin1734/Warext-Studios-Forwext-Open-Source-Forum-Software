<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Appearance;

use Forwext\Core\Domain\Entity\EntityId;

interface RoleAppearanceRepository
{
    public function find(EntityId $roleId): ?RoleAppearance;

    public function save(RoleAppearance $appearance): void;
}
