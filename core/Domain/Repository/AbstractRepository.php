<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Repository;

use Forwext\Core\Domain\Entity\Entity;
use Forwext\Core\Domain\Entity\EntityId;

/**
 * @template TEntity of Entity
 * @implements Repository<TEntity>
 */
abstract class AbstractRepository implements Repository
{
    final public function find(EntityId $id): ?Entity
    {
        return $this->findEntity($id);
    }

    final public function require(EntityId $id): Entity
    {
        $entity = $this->findEntity($id);

        if ($entity === null) {
            throw EntityNotFoundException::for($this->entityType(), $id);
        }

        return $entity;
    }

    final public function save(Entity $entity): void
    {
        $this->assertSupportedEntity($entity);
        $this->persistEntity($entity);
    }

    final public function delete(Entity $entity): void
    {
        $this->assertSupportedEntity($entity);
        $this->removeEntity($entity);
    }

    /** @return TEntity|null */
    abstract protected function findEntity(EntityId $id): ?Entity;

    /** @param TEntity $entity */
    abstract protected function persistEntity(Entity $entity): void;

    /** @param TEntity $entity */
    abstract protected function removeEntity(Entity $entity): void;

    /** @return class-string<TEntity> */
    abstract protected function entityType(): string;

    private function assertSupportedEntity(Entity $entity): void
    {
        $entityType = $this->entityType();

        if (!$entity instanceof $entityType) {
            throw new RepositoryException(sprintf(
                'Repository for %s cannot persist entity of type %s.',
                $entityType,
                $entity::class,
            ));
        }
    }
}
