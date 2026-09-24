<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

use DateTimeImmutable;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use RuntimeException;
use Throwable;

final readonly class FirstPartyModuleDataPurger
{
    public function __construct(
        private DatabaseConnection $database,
        private FirstPartyModuleRepository $repository,
        private StorageDriver $storage,
    ) {
    }

    public function purgeDatabaseAndQueueStorage(
        FirstPartyModuleDefinition $definition,
        DateTimeImmutable $at,
    ): int {
        if (!$this->database->inTransaction()) {
            throw new RuntimeException('Module data purge must run inside the lifecycle audit transaction.');
        }

        $paths = [];
        foreach ($definition->storagePathQueries as $query) {
            foreach ($this->database->fetchAll(new CompiledQuery($query)) as $row) {
                $path = $row['storage_path'] ?? null;
                if (is_string($path) && $path !== '') {
                    $paths[StoragePath::fromString($path)->value()] = true;
                }
            }
        }
        $this->repository->queueStoragePaths($definition->key, array_keys($paths), $at);

        foreach ($definition->purgeTables as $table) {
            $this->database->execute(new CompiledQuery('DELETE FROM ' . $table));
        }
        $this->repository->deleteSettings($definition->key);

        return $this->repository->pendingStoragePathCount($definition->key);
    }

    public function processStorageQueue(string $moduleKey, DateTimeImmutable $at, int $limit = 5000): int
    {
        foreach ($this->repository->pendingStoragePaths($moduleKey, $limit) as $path) {
            try {
                $storagePath = StoragePath::fromString($path);
                if ($this->storage->exists($storagePath, StorageVisibility::Private)) {
                    $this->storage->delete($storagePath, StorageVisibility::Private);
                }
                $this->repository->markStoragePathPurged($moduleKey, $path);
            } catch (Throwable $exception) {
                $this->repository->markStoragePathFailure(
                    $moduleKey,
                    $path,
                    $exception->getMessage(),
                    $at,
                );
            }
        }

        return $this->repository->pendingStoragePathCount($moduleKey);
    }
}
