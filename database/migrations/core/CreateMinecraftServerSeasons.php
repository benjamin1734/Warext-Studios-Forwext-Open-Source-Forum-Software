<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMinecraftServerSeasons implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20261004191000_minecraft_server_seasons');
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
            'CREATE TABLE IF NOT EXISTS forwext_minecraft_server_seasons ('
            . 'season_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'slug VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'name VARCHAR(120) NOT NULL,'
            . "summary VARCHAR(500) NOT NULL DEFAULT '',"
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'upcoming',"
            . 'starts_at_utc DATETIME(6) NOT NULL,'
            . 'ends_at_utc DATETIME(6) NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (season_id),'
            . 'UNIQUE KEY uq_forwext_minecraft_server_season_slug (slug),'
            . 'KEY idx_forwext_minecraft_server_season_public (state,starts_at_utc,ends_at_utc,season_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_minecraft_server_season_entries ('
            . 'season_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'server_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'joined_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (season_id,server_id),'
            . 'KEY idx_forwext_minecraft_server_season_entry_server (server_id,season_id),'
            . 'CONSTRAINT fk_forwext_minecraft_server_season_entry_season FOREIGN KEY (season_id) '
            . 'REFERENCES forwext_minecraft_server_seasons (season_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_minecraft_server_season_entry_server FOREIGN KEY (server_id) '
            . 'REFERENCES forwext_minecraft_servers (server_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_minecraft_server_seasons','forwext_minecraft_server_season_entries')",
        ));
        $foreignKeys = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_minecraft_server_season_entry_season',"
            . "'fk_forwext_minecraft_server_season_entry_server')",
        ));
        $slugIndex = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_minecraft_server_seasons' "
            . "AND INDEX_NAME='uq_forwext_minecraft_server_season_slug' AND NON_UNIQUE=0",
        ));

        return $tables === 2 && $foreignKeys === 2 && $slugIndex === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Minecraft server season schema is incomplete.');
    }
}
