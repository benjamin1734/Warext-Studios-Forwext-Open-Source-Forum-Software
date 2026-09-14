<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Repository;

use Forwext\Core\Domain\Entity\Entity;
use Forwext\Core\Domain\Entity\EntityId;

/**
 * @template TEntity of Entity
 */
interface Repository
{
    /** @return TEntity|null */
    public function find(EntityId $id): ?Entity;

    /** @return TEntity */
    public function require(EntityId $id): Entity;

    /** @param TEntity $entity */
    public function save(Entity $entity): void;

    /** @param TEntity $entity */
    public function delete(Entity $entity): void;
}
