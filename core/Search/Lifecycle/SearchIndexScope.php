<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\SearchDocument;

final class SearchIndexScope
{
    public const PUBLIC = 'public';

    public static function forumNode(EntityId $nodeId): string
    {
        $scope = 'forum.node:' . $nodeId->value();
        SearchDocument::validateScope($scope);
        return $scope;
    }
}
