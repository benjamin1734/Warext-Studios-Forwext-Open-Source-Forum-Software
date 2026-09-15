<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Url;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class EngineProfileUrlPermissionResolver implements ProfileUrlPermissionResolver
{
    public function __construct(private PermissionAuthorizer $authorizer)
    {
    }

    public function allows(EntityId $userId, ProfileUrlPermission $permission): bool
    {
        return $this->authorizer->allows($userId, PermissionKey::fromString($permission->value));
    }
}
