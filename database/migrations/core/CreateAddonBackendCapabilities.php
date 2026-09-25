<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAddonBackendCapabilities implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260925122500_addon_backend_capabilities');
    }

    public function owner(): MigrationOwner
    {
        return MigrationOwner::core();
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(MigrationContext $context): void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_addon_setting_definitions ('
            . 'addon_id VARCHAR(129) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'setting_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'value_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'label VARCHAR(120) NOT NULL,'
            . 'description VARCHAR(500) NOT NULL,'
            . 'default_value_json TEXT NOT NULL,'
            . 'constraints_json TEXT NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(addon_id,setting_key),'
            . 'UNIQUE KEY uq_forwext_addon_setting_key(setting_key),'
            . 'CONSTRAINT fk_forwext_addon_setting_definition_owner FOREIGN KEY(addon_id) '
            . 'REFERENCES forwext_addons(addon_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_addon_setting_values ('
            . 'addon_id VARCHAR(129) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'setting_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'value_json TEXT NOT NULL,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(addon_id,setting_key),'
            . 'CONSTRAINT fk_forwext_addon_setting_value_definition FOREIGN KEY(addon_id,setting_key) '
            . 'REFERENCES forwext_addon_setting_definitions(addon_id,setting_key) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_addon_setting_value_actor FOREIGN KEY(updated_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_addon_setting_definitions','forwext_addon_setting_values')",
        ));
        $definitionKey = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_addon_setting_definitions' "
            . "AND INDEX_NAME='uq_forwext_addon_setting_key'",
        ));

        return $tables === 2 && $definitionKey === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Add-on backend setting schema is incomplete.');
    }
}
