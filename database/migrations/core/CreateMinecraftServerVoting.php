<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMinecraftServerVoting implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20261004195500_minecraft_server_voting');
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
            'CREATE TABLE IF NOT EXISTS forwext_minecraft_server_votes ('
            . 'vote_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'server_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'voter_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'vote_day DATE NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (vote_id),'
            . 'UNIQUE KEY uq_forwext_minecraft_server_vote_daily (server_id,voter_user_id,vote_day),'
            . 'KEY idx_forwext_minecraft_server_vote_server (server_id,created_at_utc,vote_id),'
            . 'KEY idx_forwext_minecraft_server_vote_user (voter_user_id,created_at_utc,vote_id),'
            . 'CONSTRAINT fk_forwext_minecraft_server_vote_server FOREIGN KEY (server_id) '
            . 'REFERENCES forwext_minecraft_servers (server_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_minecraft_server_vote_user FOREIGN KEY (voter_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $permissions = [
            'minecraft_server.vote'=>'Vote for a published Minecraft server once per UTC day.',
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
            $effect = in_array($templateKey, ['member','verified','moderator','administrator'], true)
                ? 'allow'
                : 'deny';
            $context->execute(new CompiledQuery(
                'INSERT IGNORE INTO forwext_permission_template_rules '
                . '(template_key,permission_key,effect,numeric_limit) '
                . "VALUES (:template_key,'minecraft_server.vote',:effect,NULL)",
                ['template_key'=>$templateKey,'effect'=>$effect],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_minecraft_server_votes'",
        ));
        $foreignKeys = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_minecraft_server_vote_server','fk_forwext_minecraft_server_vote_user')",
        ));
        $dailyUniqueColumns = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_minecraft_server_votes' "
            . "AND INDEX_NAME='uq_forwext_minecraft_server_vote_daily' AND NON_UNIQUE=0 "
            . "AND COLUMN_NAME IN ('server_id','voter_user_id','vote_day')",
        ));
        $permission = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions "
            . "WHERE permission_key='minecraft_server.vote' AND value_type='flag'",
        ));
        $templateRules = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key='minecraft_server.vote'",
        ));

        return $table === 1
            && $foreignKeys === 2
            && $dailyUniqueColumns === 3
            && $permission === 1
            && $templateRules === 5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Minecraft server voting schema or permission defaults are incomplete.');
    }
}
