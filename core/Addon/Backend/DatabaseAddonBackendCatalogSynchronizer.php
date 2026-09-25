<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use InvalidArgumentException;
use JsonException;

final readonly class DatabaseAddonBackendCatalogSynchronizer
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function synchronize(AddonBackendRegistration $registration): void
    {
        $addonId = $registration->addonId->value();
        $state = $this->database->fetchOne(new CompiledQuery(
            'SELECT state FROM forwext_addons WHERE addon_id=:addon_id LIMIT 1',
            ['addon_id'=>$addonId],
        ));
        if ($state === null || ($state['state'] ?? null) === 'uninstalled') {
            throw new InvalidArgumentException('Backend capabilities can only be synchronized for an installed add-on.');
        }

        $this->database->transaction(function () use ($registration, $addonId): void {
            foreach ($registration->permissions() as $permission) {
                $key = $permission->key()->value();
                $existing = $this->database->fetchOne(new CompiledQuery(
                    'SELECT value_type FROM forwext_permissions WHERE permission_key=:permission_key LIMIT 1',
                    ['permission_key'=>$key],
                    true,
                ));
                if ($existing !== null && (string) $existing['value_type'] !== $permission->valueType()->value) {
                    throw new InvalidArgumentException('Add-on permission value type cannot change after registration.');
                }
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_permissions '
                    . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                    . 'VALUES (:permission_key,:value_type,:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                    . 'ON DUPLICATE KEY UPDATE description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)',
                    [
                        'permission_key'=>$key,
                        'value_type'=>$permission->valueType()->value,
                        'description'=>$permission->description(),
                    ],
                    true,
                ));
            }

            foreach ($registration->settings() as $setting) {
                $existing = $this->database->fetchOne(new CompiledQuery(
                    'SELECT value_type FROM forwext_addon_setting_definitions '
                    . 'WHERE addon_id=:addon_id AND setting_key=:setting_key LIMIT 1',
                    ['addon_id'=>$addonId,'setting_key'=>$setting->key],
                    true,
                ));
                if ($existing !== null && (string) $existing['value_type'] !== $setting->type->value) {
                    throw new InvalidArgumentException('Add-on setting value type cannot change while retained data exists.');
                }

                try {
                    $defaultJson = json_encode(
                        $setting->defaultValue,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    );
                    $constraintsJson = json_encode([
                        'minimum'=>$setting->minimum,
                        'maximum'=>$setting->maximum,
                        'allowed_strings'=>$setting->allowedStrings,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException('Add-on setting definition could not be serialized.', previous:$exception);
                }

                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_addon_setting_definitions '
                    . '(addon_id,setting_key,value_type,label,description,default_value_json,constraints_json,created_at_utc,updated_at_utc) '
                    . 'VALUES (:addon_id,:setting_key,:value_type,:label,:description,:default_json,:constraints_json,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                    . 'ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),'
                    . 'default_value_json=VALUES(default_value_json),constraints_json=VALUES(constraints_json),'
                    . 'updated_at_utc=VALUES(updated_at_utc)',
                    [
                        'addon_id'=>$addonId,
                        'setting_key'=>$setting->key,
                        'value_type'=>$setting->type->value,
                        'label'=>$setting->label,
                        'description'=>$setting->description,
                        'default_json'=>$defaultJson,
                        'constraints_json'=>$constraintsJson,
                    ],
                    true,
                ));
            }
        });
    }
}
