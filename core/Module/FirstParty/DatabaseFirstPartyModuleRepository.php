<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Storage\StoragePath;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class DatabaseFirstPartyModuleRepository implements FirstPartyModuleRepository
{
    public function __construct(private DatabaseConnection $database)
    {
    }

    public function state(string $moduleKey): FirstPartyModuleRecord
    {
        self::assertModuleKey($moduleKey);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT module_key,state,data_state,updated_by_user_id,updated_at_utc '
            . 'FROM forwext_first_party_modules WHERE module_key=:module_key LIMIT 1',
            ['module_key'=>$moduleKey],
        ));

        return $row === null
            ? new FirstPartyModuleRecord(
                $moduleKey,
                FirstPartyModuleState::Disabled,
                FirstPartyModuleDataState::Retained,
                null,
                null,
            )
            : $this->hydrateState($row);
    }

    public function states(): array
    {
        $records = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT module_key,state,data_state,updated_by_user_id,updated_at_utc '
            . 'FROM forwext_first_party_modules ORDER BY module_key',
        )) as $row) {
            $record = $this->hydrateState($row);
            $records[$record->moduleKey] = $record;
        }

        return $records;
    }

    public function saveState(
        string $moduleKey,
        FirstPartyModuleState $state,
        FirstPartyModuleDataState $dataState,
        EntityId $actor,
        DateTimeImmutable $at,
    ): void {
        self::assertModuleKey($moduleKey);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_first_party_modules '
            . '(module_key,state,data_state,updated_by_user_id,updated_at_utc) '
            . 'VALUES (:module_key,:state,:data_state,:actor,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE state=VALUES(state),data_state=VALUES(data_state),'
            . 'updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'module_key'=>$moduleKey,
                'state'=>$state->value,
                'data_state'=>$dataState->value,
                'actor'=>$actor->value(),
                'updated_at'=>self::format($at),
            ],
        ));
    }

    public function settings(string $moduleKey, FirstPartyModuleScope $scope, string $scopeId): array
    {
        self::assertModuleKey($moduleKey);
        self::assertScopeId($scope, $scopeId);

        $settings = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT setting_key,value_type,value_json FROM forwext_first_party_module_settings '
            . 'WHERE module_key=:module_key AND scope_type=:scope_type AND scope_id=:scope_id '
            . 'ORDER BY setting_key',
            [
                'module_key'=>$moduleKey,
                'scope_type'=>$scope->value,
                'scope_id'=>$scopeId,
            ],
        )) as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $key) !== 1) {
                throw new RuntimeException('Stored first-party module setting key is invalid.');
            }
            try {
                $value = json_decode((string) ($row['value_json'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored first-party module setting JSON is invalid.', previous:$exception);
            }
            $type = (string) ($row['value_type'] ?? '');
            $valid = match ($type) {
                'flag' => is_bool($value),
                'integer' => is_int($value),
                'string' => is_string($value),
                default => false,
            };
            if (!$valid) {
                throw new RuntimeException('Stored first-party module setting type is invalid.');
            }
            $settings[$key] = $value;
        }

        return $settings;
    }

    public function saveSetting(
        string $moduleKey,
        FirstPartyModuleScope $scope,
        string $scopeId,
        string $settingKey,
        bool|int|string $value,
        EntityId $actor,
        DateTimeImmutable $at,
    ): void {
        self::assertModuleKey($moduleKey);
        self::assertScopeId($scope, $scopeId);
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $settingKey) !== 1) {
            throw new InvalidArgumentException('First-party module setting key is invalid.');
        }

        $type = match (true) {
            is_bool($value) => FirstPartyModuleSettingType::Flag->value,
            is_int($value) => FirstPartyModuleSettingType::Integer->value,
            is_string($value) => FirstPartyModuleSettingType::String->value,
        };
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('First-party module setting cannot be encoded.', previous:$exception);
        }

        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_first_party_module_settings '
            . '(module_key,scope_type,scope_id,setting_key,value_type,value_json,updated_by_user_id,updated_at_utc) '
            . 'VALUES (:module_key,:scope_type,:scope_id,:setting_key,:value_type,:value_json,:actor,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),value_json=VALUES(value_json),'
            . 'updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'module_key'=>$moduleKey,
                'scope_type'=>$scope->value,
                'scope_id'=>$scopeId,
                'setting_key'=>$settingKey,
                'value_type'=>$type,
                'value_json'=>$json,
                'actor'=>$actor->value(),
                'updated_at'=>self::format($at),
            ],
        ));
    }

    public function deleteSettings(string $moduleKey): void
    {
        self::assertModuleKey($moduleKey);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_first_party_module_settings WHERE module_key=:module_key',
            ['module_key'=>$moduleKey],
        ));
    }

    public function queueStoragePaths(string $moduleKey, array $paths, DateTimeImmutable $at): void
    {
        self::assertModuleKey($moduleKey);
        foreach (array_values(array_unique($paths)) as $path) {
            if (!is_string($path)) {
                throw new InvalidArgumentException('Module purge storage path is invalid.');
            }
            $path = StoragePath::fromString($path)->value();
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_first_party_module_purge_objects '
                . '(module_key,storage_path,visibility,attempt_count,last_error,created_at_utc,updated_at_utc) '
                . "VALUES (:module_key,:storage_path,'private',0,NULL,:created_at,:updated_at) "
                . 'ON DUPLICATE KEY UPDATE updated_at_utc=VALUES(updated_at_utc)',
                [
                    'module_key'=>$moduleKey,
                    'storage_path'=>$path,
                    'created_at'=>self::format($at),
                    'updated_at'=>self::format($at),
                ],
            ));
        }
    }

    public function pendingStoragePaths(string $moduleKey, int $limit = 500): array
    {
        self::assertModuleKey($moduleKey);
        if ($limit < 1 || $limit > 5000) {
            throw new InvalidArgumentException('Module purge storage-path limit is invalid.');
        }

        return array_map(
            static fn (array $row): string => StoragePath::fromString((string) $row['storage_path'])->value(),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT storage_path FROM forwext_first_party_module_purge_objects '
                . 'WHERE module_key=:module_key ORDER BY created_at_utc,storage_path LIMIT ' . $limit,
                ['module_key'=>$moduleKey],
            )),
        );
    }

    public function markStoragePathPurged(string $moduleKey, string $path): void
    {
        self::assertModuleKey($moduleKey);
        $path = StoragePath::fromString($path)->value();
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_first_party_module_purge_objects '
            . 'WHERE module_key=:module_key AND storage_path=:storage_path',
            ['module_key'=>$moduleKey,'storage_path'=>$path],
        ));
    }

    public function markStoragePathFailure(
        string $moduleKey,
        string $path,
        string $error,
        DateTimeImmutable $at,
    ): void {
        self::assertModuleKey($moduleKey);
        $path = StoragePath::fromString($path)->value();
        $error = trim($error);
        if ($error === '') {
            $error = 'storage_delete_failed';
        }

        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_first_party_module_purge_objects '
            . 'SET attempt_count=attempt_count+1,last_error=:last_error,updated_at_utc=:updated_at '
            . 'WHERE module_key=:module_key AND storage_path=:storage_path',
            [
                'module_key'=>$moduleKey,
                'storage_path'=>$path,
                'last_error'=>substr($error, 0, 500),
                'updated_at'=>self::format($at),
            ],
        ));
    }

    public function pendingStoragePathCount(string $moduleKey): int
    {
        self::assertModuleKey($moduleKey);

        return max(0, (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_first_party_module_purge_objects WHERE module_key=:module_key',
            ['module_key'=>$moduleKey],
        )));
    }

    /** @param array<string,mixed> $row */
    private function hydrateState(array $row): FirstPartyModuleRecord
    {
        $actor = $row['updated_by_user_id'] ?? null;
        $time = $row['updated_at_utc'] ?? null;

        return new FirstPartyModuleRecord(
            (string) $row['module_key'],
            FirstPartyModuleState::from((string) $row['state']),
            FirstPartyModuleDataState::from((string) $row['data_state']),
            is_string($actor) && $actor !== '' ? EntityId::fromString($actor) : null,
            is_string($time) && $time !== '' ? self::parse($time) : null,
        );
    }

    private static function assertModuleKey(string $moduleKey): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $moduleKey) !== 1) {
            throw new InvalidArgumentException('First-party module key is invalid.');
        }
    }

    private static function assertScopeId(FirstPartyModuleScope $scope, string $scopeId): void
    {
        if (!$scope->needsTarget()) {
            if ($scopeId !== 'global') {
                throw new InvalidArgumentException('Global module setting scope id must be global.');
            }
            return;
        }
        if ($scopeId === '' || strlen($scopeId) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $scopeId) !== 1
        ) {
            throw new InvalidArgumentException('First-party module setting scope id is invalid.');
        }
    }

    private static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $time): DateTimeImmutable
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $time, new DateTimeZone('UTC'));
        if (!$value instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored module state timestamp is invalid.');
        }

        return $value;
    }
}
