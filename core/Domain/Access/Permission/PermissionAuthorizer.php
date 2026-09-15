<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Throwable;

final readonly class PermissionAuthorizer
{
    public function __construct(
        private PermissionEngine $engine,
        private UserAccessAssignmentProvider $assignments,
    ) {
    }

    public function resolve(EntityId $userId, PermissionKey $key, ?EntityId $nodeId = null): PermissionDecision
    {
        try {
            $assignment = $this->assignments->find($userId);
            if ($assignment === null) {
                return PermissionDecision::deny('missing_access_assignment');
            }

            return $this->engine->resolve($key, $assignment, $nodeId);
        } catch (Throwable) {
            return PermissionDecision::deny('access_assignment_error');
        }
    }

    public function allows(EntityId $userId, PermissionKey $key, ?EntityId $nodeId = null): bool
    {
        return $this->resolve($userId, $key, $nodeId)->isAllowed();
    }
}
