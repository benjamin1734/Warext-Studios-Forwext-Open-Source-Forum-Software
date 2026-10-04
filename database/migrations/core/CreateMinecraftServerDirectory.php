<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMinecraftServerDirectory implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20261004183000_minecraft_server_directory');
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
            'CREATE TABLE IF NOT EXISTS forwext_minecraft_servers ('
            . 'server_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'slug VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'name VARCHAR(120) NOT NULL,'
            . "summary VARCHAR(240) NOT NULL DEFAULT '',"
            . 'description MEDIUMTEXT NOT NULL,'
            . 'host VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'port SMALLINT UNSIGNED NOT NULL DEFAULT 25565,'
            . 'edition VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "version_label VARCHAR(64) NOT NULL DEFAULT '',"
            . "game_mode VARCHAR(64) NOT NULL DEFAULT '',"
            . 'website_url VARCHAR(1000) NULL,'
            . 'discord_url VARCHAR(1000) NULL,'
            . "listing_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',"
            . "verification_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unverified',"
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (server_id),'
            . 'UNIQUE KEY uq_forwext_minecraft_server_slug (slug),'
            . 'KEY idx_forwext_minecraft_server_directory '
            . '(listing_state,verification_state,edition,updated_at_utc,server_id),'
            . 'KEY idx_forwext_minecraft_server_owner (owner_user_id,listing_state,updated_at_utc),'
            . 'CONSTRAINT fk_forwext_minecraft_server_owner FOREIGN KEY (owner_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_minecraft_server_status ('
            . 'server_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "reachability VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unknown',"
            . 'online_players INT UNSIGNED NULL,'
            . 'max_players INT UNSIGNED NULL,'
            . 'latency_ms INT UNSIGNED NULL,'
            . 'motd VARCHAR(500) NULL,'
            . 'checked_at_utc DATETIME(6) NULL,'
            . 'PRIMARY KEY (server_id),'
            . 'KEY idx_forwext_minecraft_server_reachability (reachability,checked_at_utc,server_id),'
            . 'CONSTRAINT fk_forwext_minecraft_server_status_server FOREIGN KEY (server_id) '
            . 'REFERENCES forwext_minecraft_servers (server_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $moduleTable = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_first_party_modules'",
        ));
        if ($moduleTable === 1) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_first_party_modules '
                . '(module_key,state,data_state,updated_by_user_id,updated_at_utc) '
                . "VALUES ('minecraft-servers','enabled','retained',NULL,UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE module_key=VALUES(module_key)',
            ));
        }

        $permissions = [
            'minecraft_server.create'=>'Create a Minecraft server directory entry.',
            'minecraft_server.manage_any'=>'Manage any Minecraft server directory entry.',
        ];
        foreach ($permissions as $permissionKey=>$description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . "VALUES (:permission_key,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$permissionKey,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            foreach (array_keys($permissions) as $permissionKey) {
                $effect = match ($permissionKey) {
                    'minecraft_server.create' => in_array($templateKey, ['member','verified','moderator','administrator'], true)
                        ? 'allow'
                        : 'deny',
                    'minecraft_server.manage_any' => in_array($templateKey, ['moderator','administrator'], true)
                        ? 'allow'
                        : 'deny',
                    default => 'deny',
                };
                $context->execute(new CompiledQuery(
                    'INSERT IGNORE INTO forwext_permission_template_rules '
                    . '(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template_key,:permission_key,:effect,NULL)',
                    ['template_key'=>$templateKey,'permission_key'=>$permissionKey,'effect'=>$effect],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_minecraft_servers','forwext_minecraft_server_status')",
        ));
        $foreignKeys = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_minecraft_server_owner','fk_forwext_minecraft_server_status_server')",
        ));
        $permissions = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN "
            . "('minecraft_server.create','minecraft_server.manage_any') AND value_type='flag'",
        ));
        $templateRules = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('minecraft_server.create','minecraft_server.manage_any')",
        ));
        $module = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_first_party_modules WHERE module_key='minecraft-servers'",
        ));
        $slugIndex = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_minecraft_servers' AND INDEX_NAME='uq_forwext_minecraft_server_slug' "
            . 'AND NON_UNIQUE=0',
        ));

        return $tables === 2
            && $foreignKeys === 2
            && $permissions === 2
            && $templateRules === 10
            && $module === 1
            && $slugIndex === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Minecraft server directory schema or permission defaults are incomplete.');
    }
}
