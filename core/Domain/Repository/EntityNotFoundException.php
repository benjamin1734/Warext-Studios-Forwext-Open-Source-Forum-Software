<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Repository;

use Forwext\Core\Domain\Entity\EntityId;

final class EntityNotFoundException extends RepositoryException
{
    public static function for(string $entityType, EntityId $id): self
    {
        return new self(sprintf('%s entity "%s" was not found.', $entityType, $id->value()));
    }
}
