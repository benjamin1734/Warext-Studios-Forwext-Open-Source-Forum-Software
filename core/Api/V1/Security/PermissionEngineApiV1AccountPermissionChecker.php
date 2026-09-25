<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1\Security;

use Forwext\Core\Api\V1\ApiV1Scope;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class PermissionEngineApiV1AccountPermissionChecker implements ApiV1AccountPermissionChecker
{
    public function __construct(private PermissionAuthorizer $permissions)
    {
    }

    public function allows(EntityId $userId, ApiV1Scope $scope): bool
    {
        $required = match ($scope) {
            ApiV1Scope::NotificationsRead => ['notification.alert.view'],
            ApiV1Scope::SupportRead => ['support.ticket.view_own'],
            ApiV1Scope::ConversationsRead => ['support.ticket.view_own', 'bug.report.view_own'],
            default => [],
        };

        foreach ($required as $permission) {
            if (!$this->permissions->allows($userId, PermissionKey::fromString($permission))) {
                return false;
            }
        }

        return true;
    }
}
