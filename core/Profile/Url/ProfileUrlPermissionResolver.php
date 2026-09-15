<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Url;

use Forwext\Core\Domain\Entity\EntityId;

interface ProfileUrlPermissionResolver
{
    public function allows(EntityId $userId, ProfileUrlPermission $permission): bool;
}
