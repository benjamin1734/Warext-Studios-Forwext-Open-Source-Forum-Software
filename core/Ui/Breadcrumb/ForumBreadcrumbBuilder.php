<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Breadcrumb;

use Closure;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;

final readonly class ForumBreadcrumbBuilder
{
    public function __construct(private PermissionAuthorizer $authorizer)
    {
    }

    /** @param Closure(ForumNode): ?string $pathResolver */
    public function build(
        EntityId $actorId,
        ForumNodeHierarchy $hierarchy,
        EntityId $nodeId,
        Closure $pathResolver,
    ): BreadcrumbTrail {
        $items = [new BreadcrumbItem('Ana Sayfa', '/')];
        $permission = PermissionKey::fromString('forum.view');

        foreach ($hierarchy->breadcrumb($nodeId) as $node) {
            $decision = $this->authorizer->resolve($actorId, $permission, $node->id());
            if (!$decision->isAllowed()) {
                throw new PermissionDeniedException($decision);
            }
            $items[] = new BreadcrumbItem($node->title(), $pathResolver($node));
        }

        return new BreadcrumbTrail($items);
    }
}
