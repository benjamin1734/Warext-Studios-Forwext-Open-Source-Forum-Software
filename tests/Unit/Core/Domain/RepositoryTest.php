<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain;

use Forwext\Core\Domain\Entity\Entity;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Repository\AbstractRepository;
use Forwext\Core\Domain\Repository\EntityNotFoundException;
use Forwext\Core\Domain\Repository\RepositoryException;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    public function testRepositoryFindRequireSaveAndDeleteContract(): void
    {
        $repository = new FixtureRepository();
        $entity = new FixtureEntity(EntityId::fromInt(1), 'alpha');

        $repository->save($entity);

        self::assertSame($entity, $repository->find(EntityId::fromInt(1)));
        self::assertSame($entity, $repository->require(EntityId::fromInt(1)));

        $repository->delete($entity);
        self::assertNull($repository->find(EntityId::fromInt(1)));
    }

    public function testRequireThrowsTypedNotFoundException(): void
    {
        $this->expectException(EntityNotFoundException::class);
        (new FixtureRepository())->require(EntityId::fromInt(999));
    }

    public function testRepositoryRejectsWrongEntityType(): void
    {
        $this->expectException(RepositoryException::class);
        (new FixtureRepository())->save(new OtherFixtureEntity(EntityId::fromInt(2)));
    }
}

final readonly class FixtureEntity implements Entity
{
    public function __construct(
        private EntityId $entityId,
        public string $name,
    ) {
    }

    public function id(): EntityId
    {
        return $this->entityId;
    }
}

final readonly class OtherFixtureEntity implements Entity
{
    public function __construct(private EntityId $entityId)
    {
    }

    public function id(): EntityId
    {
        return $this->entityId;
    }
}

/** @extends AbstractRepository<FixtureEntity> */
final class FixtureRepository extends AbstractRepository
{
    /** @var array<string, FixtureEntity> */
    private array $entities = [];

    protected function findEntity(EntityId $id): ?Entity
    {
        return $this->entities[$id->value()] ?? null;
    }

    protected function persistEntity(Entity $entity): void
    {
        if (!$entity instanceof FixtureEntity) {
            throw new RepositoryException('FixtureRepository received an unsupported entity.');
        }

        $this->entities[$entity->id()->value()] = $entity;
    }

    protected function removeEntity(Entity $entity): void
    {
        if (!$entity instanceof FixtureEntity) {
            throw new RepositoryException('FixtureRepository received an unsupported entity.');
        }

        unset($this->entities[$entity->id()->value()]);
    }

    protected function entityType(): string
    {
        return FixtureEntity::class;
    }
}
