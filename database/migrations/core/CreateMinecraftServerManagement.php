<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMinecraftServerManagement implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20261004194500_minecraft_server_management');
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
            'CREATE TABLE IF NOT EXISTS forwext_minecraft_server_claims ('
            . 'claim_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'server_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'claimant_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'proof_note VARCHAR(1000) NOT NULL,'
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . 'reviewed_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'review_note VARCHAR(1000) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'reviewed_at_utc DATETIME(6) NULL,'
            . 'PRIMARY KEY (claim_id),'
            . 'KEY idx_forwext_minecraft_server_claim_server (server_id,state,created_at_utc),'
            . 'KEY idx_forwext_minecraft_server_claim_user (claimant_user_id,state,created_at_utc),'
            . 'CONSTRAINT fk_forwext_minecraft_server_claim_server FOREIGN KEY (server_id) '
            . 'REFERENCES forwext_minecraft_servers (server_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_minecraft_server_claim_user FOREIGN KEY (claimant_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_minecraft_server_claim_reviewer FOREIGN KEY (reviewed_by_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_minecraft_server_ownership_events ('
            . 'event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'server_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'event_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'from_owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'to_owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'detail VARCHAR(500) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (event_id),'
            . 'KEY idx_forwext_minecraft_server_owner_event (server_id,created_at_utc,event_id),'
            . 'CONSTRAINT fk_forwext_minecraft_server_owner_event_server FOREIGN KEY (server_id) '
            . 'REFERENCES forwext_minecraft_servers (server_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_minecraft_server_owner_event_actor FOREIGN KEY (actor_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_minecraft_server_owner_event_from FOREIGN KEY (from_owner_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_minecraft_server_owner_event_to FOREIGN KEY (to_owner_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $permissions = [
            'minecraft_server.manage_own'=>'Manage a Minecraft server directory entry owned by the account.',
            'minecraft_server.claim'=>'Submit an ownership claim for an unowned Minecraft server entry.',
            'minecraft_server.transfer'=>'Transfer or release an owned Minecraft server entry.',
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
            . "AND TABLE_NAME IN ('forwext_minecraft_server_claims','forwext_minecraft_server_ownership_events')",
        ));
        $foreignKeys = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_minecraft_server_claim_server','fk_forwext_minecraft_server_claim_user',"
            . "'fk_forwext_minecraft_server_claim_reviewer','fk_forwext_minecraft_server_owner_event_server',"
            . "'fk_forwext_minecraft_server_owner_event_actor','fk_forwext_minecraft_server_owner_event_from',"
            . "'fk_forwext_minecraft_server_owner_event_to')",
        ));
        $permissions = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN "
            . "('minecraft_server.manage_own','minecraft_server.claim','minecraft_server.transfer') "
            . "AND value_type='flag'",
        ));
        $templateRules = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('minecraft_server.manage_own','minecraft_server.claim','minecraft_server.transfer')",
        ));

        return $tables === 2 && $foreignKeys === 7 && $permissions === 3 && $templateRules === 15
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Minecraft server management schema or permission defaults are incomplete.');
    }
}
