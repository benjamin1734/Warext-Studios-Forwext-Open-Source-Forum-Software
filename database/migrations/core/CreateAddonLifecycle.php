<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAddonLifecycle implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260925121000_addon_lifecycle');
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
            'CREATE TABLE IF NOT EXISTS forwext_addons ('
            . 'addon_id VARCHAR(129) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'vendor_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'addon_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'title VARCHAR(100) NOT NULL,'
            . 'version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'disabled',"
            . "data_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'retained',"
            . 'package_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'manifest_json MEDIUMTEXT NOT NULL,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(addon_id),'
            . 'KEY idx_forwext_addon_state(state,addon_id),'
            . 'KEY idx_forwext_addon_vendor(vendor_id,addon_name),'
            . 'CONSTRAINT fk_forwext_addon_actor FOREIGN KEY(updated_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_addon_relations ('
            . 'addon_id VARCHAR(129) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'relation_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'target_addon_id VARCHAR(129) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'version_constraint VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY(addon_id,relation_type,target_addon_id),'
            . 'KEY idx_forwext_addon_relation_target(target_addon_id,relation_type,addon_id),'
            . 'CONSTRAINT fk_forwext_addon_relation_owner FOREIGN KEY(addon_id) '
            . 'REFERENCES forwext_addons(addon_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_permissions '
            . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
            . "VALUES ('addon.manage','flag','Install, upgrade, enable, disable and uninstall third-party add-ons.',"
            . 'UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
            . 'updated_at_utc=VALUES(updated_at_utc)',
        ));

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_template_rules '
                . '(template_key,permission_key,effect,numeric_limit) '
                . "VALUES (:template_key,'addon.manage',:effect,NULL) "
                . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                [
                    'template_key'=>$templateKey,
                    'effect'=>$templateKey === 'administrator' ? 'allow' : 'deny',
                ],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_addons','forwext_addon_relations')",
        ));
        $permission = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key='addon.manage' AND value_type='flag'",
        ));
        $rules = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE permission_key='addon.manage' "
            . "AND template_key IN ('new_user','member','verified','moderator','administrator')",
        ));
        $adminAllow = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE permission_key='addon.manage' AND template_key='administrator' AND effect='allow'",
        ));

        return $tables === 2 && $permission === 1 && $rules === 5 && $adminAllow === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Third-party add-on lifecycle schema or permissions are incomplete.');
    }
}
