<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Url;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class BaselineProfileUrlPermissionResolver implements ProfileUrlPermissionResolver
{
    public function __construct(private bool $use = true)
    {
    }

    public function allows(EntityId $userId, ProfileUrlPermission $permission): bool
    {
        UserId::assert($userId);
        return $permission === ProfileUrlPermission::Use && $this->use;
    }
}
