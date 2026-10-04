<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMinecraftServerTeamAndVoteIntegration implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20261004203000_minecraft_server_team_vote_integration');
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
            'CREATE TABLE IF NOT EXISTS forwext_minecraft_server_team_members ('
            . 'server_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "role_key VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'member',"
            . 'public_title VARCHAR(64) NULL,'
            . 'added_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (server_id,user_id),'
            . 'KEY idx_forwext_minecraft_server_team_user (user_id,role_key,server_id),'
            . 'CONSTRAINT fk_forwext_minecraft_server_team_server FOREIGN KEY (server_id) '
            . 'REFERENCES forwext_minecraft_servers (server_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_minecraft_server_team_user FOREIGN KEY (user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_minecraft_server_team_added_by FOREIGN KEY (added_by_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_minecraft_server_vote_integrations ('
            . 'server_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . 'token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'token_prefix VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'last_rotated_at_utc DATETIME(6) NULL,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (server_id),'
            . 'CONSTRAINT fk_forwext_minecraft_server_vote_integration_server FOREIGN KEY (server_id) '
            . 'REFERENCES forwext_minecraft_servers (server_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_minecraft_server_vote_integration_user FOREIGN KEY (updated_by_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $permissions = [
            'minecraft_server.team.manage'=>'Manage team membership for an owned Minecraft server.',
            'minecraft_server.vote_integration.manage'=>'Manage vote integration settings for an owned Minecraft server.',
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
                $effect = in_array($templateKey, ['member','verified','moderator','administrator'], true)
                    ? 'allow'
                    : 'deny';
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
            . "AND TABLE_NAME IN ('forwext_minecraft_server_team_members','forwext_minecraft_server_vote_integrations')",
        ));
        $foreignKeys = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_minecraft_server_team_server','fk_forwext_minecraft_server_team_user',"
            . "'fk_forwext_minecraft_server_team_added_by','fk_forwext_minecraft_server_vote_integration_server',"
            . "'fk_forwext_minecraft_server_vote_integration_user')",
        ));
        $permissions = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN "
            . "('minecraft_server.team.manage','minecraft_server.vote_integration.manage') AND value_type='flag'",
        ));
        $templateRules = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('minecraft_server.team.manage','minecraft_server.vote_integration.manage')",
        ));

        return $tables === 2 && $foreignKeys === 5 && $permissions === 2 && $templateRules === 10
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Minecraft server team or vote integration schema is incomplete.');
    }
}
