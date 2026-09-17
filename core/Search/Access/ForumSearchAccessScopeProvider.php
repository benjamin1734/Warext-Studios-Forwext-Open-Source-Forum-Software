<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Access;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Search\Lifecycle\SearchIndexScope;

final readonly class ForumSearchAccessScopeProvider implements SearchAccessScopeProvider
{
    private const VIEW = 'forum.view';

    public function __construct(
        private ForumNodeRepository $nodes,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function scopes(EntityId $userId): array
    {
        $nodes = $this->nodes->all();
        $hierarchy = new ForumNodeHierarchy($nodes);
        $key = PermissionKey::fromString(self::VIEW);
        $scopes = [];
        foreach ($nodes as $node) {
            if (
                $hierarchy->isDiscoverable($node->id())
                && $this->authorizer->allows($userId, $key, $node->id())
            ) {
                $scopes[] = SearchIndexScope::forumNode($node->id());
            }
        }
        return $scopes;
    }
}
