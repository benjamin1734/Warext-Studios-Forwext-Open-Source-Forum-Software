<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Node;

use Forwext\Core\Domain\Access\Permission\PermissionDecision;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class ForumNodeAuthorization
{
    private const VIEW_PERMISSION = 'forum.view';

    public function __construct(private PermissionGate $gate)
    {
    }

    public function viewDecision(ForumNodeHierarchy $hierarchy, EntityId $nodeId): PermissionDecision
    {
        if ($hierarchy->find($nodeId) === null) {
            return PermissionDecision::deny('forum_node_not_found');
        }
        if (!$hierarchy->isResolvable($nodeId)) {
            return PermissionDecision::deny('forum_node_disabled');
        }

        return $this->gate->decision(PermissionKey::fromString(self::VIEW_PERMISSION), $nodeId);
    }

    public function canView(ForumNodeHierarchy $hierarchy, EntityId $nodeId): bool
    {
        return $this->viewDecision($hierarchy, $nodeId)->isAllowed();
    }

    public function requireView(ForumNodeHierarchy $hierarchy, EntityId $nodeId): PermissionDecision
    {
        $decision = $this->viewDecision($hierarchy, $nodeId);
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }

        return $decision;
    }
}
