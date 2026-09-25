<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class DatabaseAddonRepository implements AddonRepository
{
    public function __construct(private DatabaseConnection $database)
    {
    }

    public function find(AddonId $id): ?AddonInstallation
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT addon_id,manifest_json,state,data_state,package_checksum FROM forwext_addons '
            . 'WHERE addon_id=:addon_id LIMIT 1',
            ['addon_id'=>$id->value()],
        ));

        return $row === null ? null : self::hydrate($row);
    }

    public function all(): array
    {
        $result = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT addon_id,manifest_json,state,data_state,package_checksum FROM forwext_addons ORDER BY addon_id',
        )) as $row) {
            $installation = self::hydrate($row);
            $result[$installation->manifest->id->value()] = $installation;
        }

        return $result;
    }

    public function save(AddonInstallation $installation, EntityId $actor, DateTimeImmutable $at): void
    {
        $manifest = $installation->manifest;
        $timestamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $this->database->transaction(function () use ($installation, $manifest, $actor, $timestamp): void {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_addons '
                . '(addon_id,vendor_id,addon_name,title,version,state,data_state,package_checksum,manifest_json,updated_by_user_id,created_at_utc,updated_at_utc) '
                . 'VALUES (:id,:vendor,:name,:title,:version,:state,:data_state,:checksum,:manifest,:actor,:created_at,:updated_at) '
                . 'ON DUPLICATE KEY UPDATE vendor_id=VALUES(vendor_id),addon_name=VALUES(addon_name),title=VALUES(title),'
                . 'version=VALUES(version),state=VALUES(state),data_state=VALUES(data_state),package_checksum=VALUES(package_checksum),'
                . 'manifest_json=VALUES(manifest_json),updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
                [
                    'id'=>$manifest->id->value(),
                    'vendor'=>$manifest->id->vendor(),
                    'name'=>$manifest->id->name(),
                    'title'=>$manifest->title,
                    'version'=>$manifest->version->value(),
                    'state'=>$installation->state->value,
                    'data_state'=>$installation->dataState->value,
                    'checksum'=>$installation->packageChecksum,
                    'manifest'=>$manifest->normalizedJson(),
                    'actor'=>$actor->value(),
                    'created_at'=>$timestamp,
                    'updated_at'=>$timestamp,
                ],
            ));
            $this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_addon_relations WHERE addon_id=:addon_id',
                ['addon_id'=>$manifest->id->value()],
            ));
            foreach (['requires'=>$manifest->requires, 'conflicts'=>$manifest->conflicts] as $type=>$relations) {
                foreach ($relations as $targetId=>$constraint) {
                    $this->database->execute(new CompiledQuery(
                        'INSERT INTO forwext_addon_relations '
                        . '(addon_id,relation_type,target_addon_id,version_constraint) '
                        . 'VALUES (:addon_id,:relation_type,:target_id,:constraint)',
                        [
                            'addon_id'=>$manifest->id->value(),
                            'relation_type'=>$type,
                            'target_id'=>$targetId,
                            'constraint'=>$constraint->value(),
                        ],
                    ));
                }
            }
        });
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): AddonInstallation
    {
        foreach (['addon_id','manifest_json','state','data_state','package_checksum'] as $key) {
            if (!isset($row[$key]) || !is_string($row[$key])) {
                throw new InvalidArgumentException('Stored add-on lifecycle row is malformed.');
            }
        }

        $manifest = AddonManifest::fromJson($row['manifest_json']);
        if ($manifest->id->value() !== $row['addon_id']) {
            throw new InvalidArgumentException('Stored add-on manifest id does not match its row id.');
        }

        return new AddonInstallation(
            $manifest,
            AddonState::from($row['state']),
            AddonDataState::from($row['data_state']),
            $row['package_checksum'],
        );
    }
}
