<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class PermissionGate
{
    public function __construct(
        private PermissionAuthorizer $authorizer,
        private EntityId $actorId,
    ) {
        UserId::assert($this->actorId);
    }

    public function actorId(): EntityId
    {
        return $this->actorId;
    }

    public function decision(PermissionKey $key, ?EntityId $nodeId = null): PermissionDecision
    {
        return $this->authorizer->resolve($this->actorId, $key, $nodeId);
    }

    public function allows(PermissionKey $key, ?EntityId $nodeId = null): bool
    {
        return $this->decision($key, $nodeId)->isAllowed();
    }

    public function require(PermissionKey $key, ?EntityId $nodeId = null): PermissionDecision
    {
        $decision = $this->decision($key, $nodeId);
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }

        return $decision;
    }
}
