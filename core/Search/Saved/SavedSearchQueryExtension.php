<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Saved;

use Forwext\Core\Domain\Entity\EntityId;

interface SavedSearchQueryExtension
{
    public function key(): string;

    public function query(EntityId $userId): SavedSearchQuery;
}
