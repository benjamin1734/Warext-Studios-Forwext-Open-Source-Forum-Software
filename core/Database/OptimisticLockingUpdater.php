<?php

declare(strict_types=1);

namespace Forwext\Core\Database;

use Forwext\Core\Database\Query\UpdateQueryBuilder;

final readonly class OptimisticLockingUpdater
{
    public function __construct(private QueryExecutor $executor)
    {
    }

    /**
     * @param string|int $id
     * @param array<string, string|int|float|bool|null> $changes
     */
    public function update(
        string $table,
        string $idColumn,
        string|int $id,
        string $versionColumn,
        int $expectedVersion,
        array $changes,
    ): int {
        if ($expectedVersion < 0 || $expectedVersion === PHP_INT_MAX) {
            throw new DatabaseException('Expected optimistic-lock version is out of range.');
        }
        if ($changes === []) {
            throw new DatabaseException('Optimistic update requires at least one changed field.');
        }
        if (array_key_exists($idColumn, $changes) || array_key_exists($versionColumn, $changes)) {
            throw new DatabaseException('Optimistic update may not replace the identity or version column directly.');
        }

        $newVersion = $expectedVersion + 1;
        $builder = (new UpdateQueryBuilder($table))
            ->setMany($changes)
            ->set($versionColumn, $newVersion)
            ->whereEquals($idColumn, $id)
            ->whereEquals($versionColumn, $expectedVersion);

        $affected = $this->executor->execute($builder->compile());
        if ($affected !== 1) {
            throw new OptimisticLockException(sprintf(
                'Optimistic update expected exactly one row but affected %d.',
                $affected,
            ));
        }

        return $newVersion;
    }
}
