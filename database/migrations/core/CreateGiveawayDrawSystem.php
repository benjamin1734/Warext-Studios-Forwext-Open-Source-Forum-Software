<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateGiveawayDrawSystem implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919153000_giveaway_draw_system');
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
            'CREATE TABLE IF NOT EXISTS forwext_giveaway_draws ('
            . 'draw_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'giveaway_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'sequence INT UNSIGNED NOT NULL,'
            . "kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . 'parent_draw_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'redraw_reason VARCHAR(500) NULL,'
            . 'algorithm VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'seed_hex CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'population_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'participant_count INT UNSIGNED NOT NULL,total_weight BIGINT UNSIGNED NOT NULL,'
            . 'selected_ticket BIGINT UNSIGNED NOT NULL,'
            . 'winner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'winner_entry_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'winner_entry_weight SMALLINT UNSIGNED NOT NULL,'
            . 'created_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'proof_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(draw_id),'
            . 'UNIQUE KEY uq_forwext_giveaway_draw_sequence(giveaway_id,sequence),'
            . 'UNIQUE KEY uq_forwext_giveaway_draw_parent(parent_draw_id),'
            . 'KEY idx_forwext_giveaway_draw_winner(winner_user_id,giveaway_id),'
            . 'KEY idx_forwext_giveaway_draw_created(giveaway_id,created_at_utc),'
            . 'CONSTRAINT fk_forwext_giveaway_draw_giveaway FOREIGN KEY(giveaway_id) '
            . 'REFERENCES forwext_giveaways(giveaway_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_giveaway_draw_parent FOREIGN KEY(parent_draw_id) '
            . 'REFERENCES forwext_giveaway_draws(draw_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_giveaway_draw_actor FOREIGN KEY(created_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_giveaway_draws'",
        ));
        $indexes = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_giveaway_draws' "
            . "AND INDEX_NAME IN ('uq_forwext_giveaway_draw_sequence','uq_forwext_giveaway_draw_parent',"
            . "'idx_forwext_giveaway_draw_winner','idx_forwext_giveaway_draw_created')",
        ));
        return $table === 1 && $indexes === 4
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Giveaway draw schema is incomplete.');
    }
}
