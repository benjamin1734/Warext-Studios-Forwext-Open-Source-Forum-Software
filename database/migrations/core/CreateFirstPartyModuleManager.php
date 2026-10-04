<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateFirstPartyModuleManager implements Migration
{
    private const MODULE_KEYS = [
        'support',
        'faq',
        'bug-reports',
        'portfolio',
        'referral',
        'ai-moderation',
        'spellcheck',
        'content-manager',
        'thread-freshness',
        'giveaway',
        'easter-egg',
        'trophies',
        'rewards',
        'promotions',
        'marketplace',
        'payments',
        'subscriptions',
        'advertising',
        'analytics',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260924212000_first_party_module_manager');
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
            'CREATE TABLE IF NOT EXISTS forwext_first_party_modules ('
            . 'module_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'enabled',"
            . "data_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'retained',"
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(module_key),'
            . 'KEY idx_forwext_first_party_module_state(state,module_key),'
            . 'CONSTRAINT fk_forwext_first_party_module_actor FOREIGN KEY(updated_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_first_party_module_settings ('
            . 'module_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'scope_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'scope_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'setting_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'value_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'value_json TEXT NOT NULL,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(module_key,scope_type,scope_id,setting_key),'
            . 'KEY idx_forwext_module_setting_scope(scope_type,scope_id,module_key),'
            . 'CONSTRAINT fk_forwext_module_setting_module FOREIGN KEY(module_key) '
            . 'REFERENCES forwext_first_party_modules(module_key) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_module_setting_actor FOREIGN KEY(updated_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_first_party_module_purge_objects ('
            . 'module_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'storage_path VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "visibility VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'private',"
            . 'attempt_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'last_error VARCHAR(500) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(module_key,storage_path),'
            . 'CONSTRAINT fk_forwext_module_purge_object_module FOREIGN KEY(module_key) '
            . 'REFERENCES forwext_first_party_modules(module_key) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach (self::MODULE_KEYS as $moduleKey) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_first_party_modules '
                . "(module_key,state,data_state,updated_by_user_id,updated_at_utc) "
                . "VALUES (:module_key,'enabled','retained',NULL,UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE module_key=VALUES(module_key)',
                ['module_key'=>$moduleKey],
            ));
        }

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_permissions '
            . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
            . "VALUES ('module.manage','flag','Manage first-party module lifecycle, scoped settings and uninstall data policy.',"
            . 'UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
            . 'updated_at_utc=VALUES(updated_at_utc)',
        ));

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_template_rules '
                . '(template_key,permission_key,effect,numeric_limit) '
                . "VALUES (:template_key,'module.manage',:effect,NULL) "
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
            . "AND TABLE_NAME IN ('forwext_first_party_modules','forwext_first_party_module_settings',"
            . "'forwext_first_party_module_purge_objects')",
        ));
        $modules = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_first_party_modules',
        ));
        $permission = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key='module.manage' AND value_type='flag'",
        ));
        $rules = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE permission_key='module.manage' "
            . "AND template_key IN ('new_user','member','verified','moderator','administrator')",
        ));
        $adminAllow = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE permission_key='module.manage' AND template_key='administrator' AND effect='allow'",
        ));

        return $tables === 3
            && $modules === count(self::MODULE_KEYS)
            && $permission === 1
            && $rules === 5
            && $adminAllow === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('First-party module manager schema, seeds or permissions are incomplete.');
    }
}
