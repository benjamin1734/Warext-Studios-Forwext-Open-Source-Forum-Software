<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Access;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class AnalyticsAccessService
{
    private const SUPER_PERMISSION = 'analytics.view_site';

    public function __construct(private PermissionAuthorizer $authorizer)
    {
    }

    public function allows(EntityId $actor, string $permission): bool
    {
        $key = PermissionKey::fromString($permission);
        if ($permission === self::SUPER_PERMISSION) {
            return $this->authorizer->allows($actor, $key);
        }

        return $this->authorizer->allows($actor, PermissionKey::fromString(self::SUPER_PERMISSION))
            || $this->authorizer->allows($actor, $key);
    }

    public function require(EntityId $actor, string $permission): void
    {
        $site = $this->authorizer->resolve($actor, PermissionKey::fromString(self::SUPER_PERMISSION));
        if ($site->isAllowed()) {
            return;
        }

        $scoped = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$scoped->isAllowed()) {
            throw new PermissionDeniedException($scoped);
        }
    }
}
