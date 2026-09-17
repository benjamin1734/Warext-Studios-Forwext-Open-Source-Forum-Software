<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Access;

use Forwext\Core\Domain\Entity\EntityId;

interface SearchAccessScopeProvider
{
    /** @return list<string> */
    public function scopes(EntityId $userId): array;
}
