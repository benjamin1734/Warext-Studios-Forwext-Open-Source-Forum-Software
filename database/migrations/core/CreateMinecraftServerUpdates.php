<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMinecraftServerUpdates implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20261004201000_minecraft_server_updates');
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
            'CREATE TABLE IF NOT EXISTS forwext_minecraft_server_updates ('
            . 'update_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'server_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'author_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'title VARCHAR(160) NOT NULL,'
            . 'body TEXT NOT NULL,'
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'published',"
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (update_id),'
            . 'KEY idx_forwext_minecraft_server_update_public (server_id,state,created_at_utc,update_id),'
            . 'CONSTRAINT fk_forwext_minecraft_server_update_server FOREIGN KEY (server_id) '
            . 'REFERENCES forwext_minecraft_servers (server_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_minecraft_server_update_author FOREIGN KEY (author_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_minecraft_server_updates'",
        ));
        $foreignKeys = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_minecraft_server_update_server','fk_forwext_minecraft_server_update_author')",
        ));
        $index = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'forwext_minecraft_server_updates\' '
            . "AND INDEX_NAME='idx_forwext_minecraft_server_update_public'",
        ));

        return $table === 1 && $foreignKeys === 2 && $index === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Minecraft server update persistence is incomplete.');
    }
}
