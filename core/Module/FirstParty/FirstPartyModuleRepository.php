<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface FirstPartyModuleRepository
{
    public function state(string $moduleKey): FirstPartyModuleRecord;

    /** @return array<string,FirstPartyModuleRecord> */
    public function states(): array;

    public function saveState(
        string $moduleKey,
        FirstPartyModuleState $state,
        FirstPartyModuleDataState $dataState,
        EntityId $actor,
        DateTimeImmutable $at,
    ): void;

    /** @return array<string,bool|int|string> */
    public function settings(string $moduleKey, FirstPartyModuleScope $scope, string $scopeId): array;

    public function saveSetting(
        string $moduleKey,
        FirstPartyModuleScope $scope,
        string $scopeId,
        string $settingKey,
        bool|int|string $value,
        EntityId $actor,
        DateTimeImmutable $at,
    ): void;

    public function deleteSetting(
        string $moduleKey,
        FirstPartyModuleScope $scope,
        string $scopeId,
        string $settingKey,
    ): void;

    public function deleteSettings(string $moduleKey): void;

    /** @param list<string> $paths */
    public function queueStoragePaths(string $moduleKey, array $paths, DateTimeImmutable $at): void;

    /** @return list<string> */
    public function pendingStoragePaths(string $moduleKey, int $limit = 500): array;

    public function markStoragePathPurged(string $moduleKey, string $path): void;

    public function markStoragePathFailure(string $moduleKey, string $path, string $error, DateTimeImmutable $at): void;

    public function pendingStoragePathCount(string $moduleKey): int;
}
