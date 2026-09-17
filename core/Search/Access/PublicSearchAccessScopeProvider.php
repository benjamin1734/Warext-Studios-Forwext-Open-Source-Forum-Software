<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Access;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Lifecycle\SearchIndexScope;

final readonly class PublicSearchAccessScopeProvider implements SearchAccessScopeProvider
{
    public function scopes(EntityId $userId): array
    {
        return [SearchIndexScope::PUBLIC];
    }
}
