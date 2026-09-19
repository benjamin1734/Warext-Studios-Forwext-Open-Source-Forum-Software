<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateEasterEggSystem implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919160000_easter_egg_system');
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
            'CREATE TABLE IF NOT EXISTS forwext_easter_egg_settings ('
            . 'setting_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(setting_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            "INSERT INTO forwext_easter_egg_settings(setting_key,enabled,updated_at_utc) "
            . "VALUES ('global',0,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)",
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_easter_eggs ('
            . 'easter_egg_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'egg_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'name VARCHAR(100) NOT NULL,enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . 'priority SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . "trigger_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'automatic',"
            . 'trigger_value VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'route_name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'path_pattern VARCHAR(255) NOT NULL,starts_at_utc DATETIME(6) NULL,ends_at_utc DATETIME(6) NULL,'
            . 'message VARCHAR(1000) NOT NULL,'
            . "animation VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',"
            . 'badge_label VARCHAR(40) NULL,created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(easter_egg_id),UNIQUE KEY uq_forwext_easter_egg_key(egg_key),'
            . 'KEY idx_forwext_easter_egg_active(enabled,starts_at_utc,ends_at_utc,priority),'
            . 'KEY idx_forwext_easter_egg_route(route_name,enabled,priority)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_easter_egg_groups ('
            . 'easter_egg_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'group_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY(easter_egg_id,group_id),KEY idx_forwext_easter_egg_group(group_id,easter_egg_id),'
            . 'CONSTRAINT fk_forwext_easter_egg_group_egg FOREIGN KEY(easter_egg_id) '
            . 'REFERENCES forwext_easter_eggs(easter_egg_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_easter_egg_group_group FOREIGN KEY(group_id) '
            . 'REFERENCES forwext_user_groups(group_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $profiles = [
            'new_user'=>'deny',
            'member'=>'deny',
            'verified'=>'deny',
            'moderator'=>'deny',
            'administrator'=>'allow',
        ];
        foreach ($profiles as $template=>$effect) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                . "VALUES (:template,'easteregg.manage',:effect,NULL) "
                . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                ['template'=>$template,'effect'=>$effect],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME IN ('forwext_easter_egg_settings','forwext_easter_eggs','forwext_easter_egg_groups')",
        ));
        $global = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_easter_egg_settings WHERE setting_key='global' AND enabled=0",
        ));
        $rules = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE permission_key='easteregg.manage' "
            . "AND template_key IN ('new_user','member','verified','moderator','administrator')",
        ));

        return $tables === 3 && $global === 1 && $rules === 5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Easter egg schema, safe global default or permission templates are incomplete.');
    }
}
