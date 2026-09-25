<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use JsonException;

final readonly class DatabaseAddonSettingStore implements AddonSettingStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function resolve(AddonId $addonId, AddonSettingDefinition $definition): bool|int|string
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT value_json FROM forwext_addon_setting_values '
            . 'WHERE addon_id=:addon_id AND setting_key=:setting_key LIMIT 1',
            ['addon_id'=>$addonId->value(),'setting_key'=>$definition->key],
        ));
        if ($row === null) {
            return $definition->defaultValue;
        }

        $json = $row['value_json'] ?? null;
        if (!is_string($json)) {
            throw new InvalidArgumentException('Stored add-on setting value is malformed.');
        }
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stored add-on setting value is invalid JSON.', previous:$exception);
        }

        return $definition->normalize($decoded);
    }

    public function save(
        AddonId $addonId,
        AddonSettingDefinition $definition,
        mixed $value,
        EntityId $actor,
        DateTimeImmutable $at,
    ): bool|int|string {
        $normalized = $definition->normalize($value);
        try {
            $json = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Add-on setting value could not be serialized.', previous:$exception);
        }
        $timestamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_addon_setting_values '
            . '(addon_id,setting_key,value_json,updated_by_user_id,updated_at_utc) '
            . 'VALUES (:addon_id,:setting_key,:value_json,:actor,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),'
            . 'updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'addon_id'=>$addonId->value(),
                'setting_key'=>$definition->key,
                'value_json'=>$json,
                'actor'=>$actor->value(),
                'updated_at'=>$timestamp,
            ],
            requiresTransaction:true,
        ));

        return $normalized;
    }

    public function reset(AddonId $addonId, AddonSettingDefinition $definition): void
    {
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_addon_setting_values WHERE addon_id=:addon_id AND setting_key=:setting_key',
            ['addon_id'=>$addonId->value(),'setting_key'=>$definition->key],
            requiresTransaction:true,
        ));
    }
}
