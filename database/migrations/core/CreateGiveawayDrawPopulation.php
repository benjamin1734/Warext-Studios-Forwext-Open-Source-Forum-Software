<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateGiveawayDrawPopulation implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919153500_giveaway_draw_population');
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
            'CREATE TABLE IF NOT EXISTS forwext_giveaway_draw_population ('
            . 'draw_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'ordinal INT UNSIGNED NOT NULL,'
            . 'entry_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'entry_weight SMALLINT UNSIGNED NOT NULL,'
            . 'PRIMARY KEY(draw_id,ordinal),'
            . 'UNIQUE KEY uq_forwext_draw_population_entry(draw_id,entry_id),'
            . 'UNIQUE KEY uq_forwext_draw_population_user(draw_id,user_id),'
            . 'CONSTRAINT fk_forwext_draw_population_draw FOREIGN KEY(draw_id) '
            . 'REFERENCES forwext_giveaway_draws(draw_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $parentConstraint = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='forwext_giveaway_draws' "
            . "AND CONSTRAINT_NAME='fk_forwext_giveaway_draw_parent'",
        ));
        if ($parentConstraint === 1) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE forwext_giveaway_draws DROP FOREIGN KEY fk_forwext_giveaway_draw_parent',
            ));
        }
        $context->execute(new CompiledQuery(
            'ALTER TABLE forwext_giveaway_draws ADD CONSTRAINT fk_forwext_giveaway_draw_parent '
            . 'FOREIGN KEY(parent_draw_id) REFERENCES forwext_giveaway_draws(draw_id) ON DELETE CASCADE',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_giveaway_draw_population'",
        ));
        $indexes = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_giveaway_draw_population' "
            . "AND INDEX_NAME IN ('PRIMARY','uq_forwext_draw_population_entry','uq_forwext_draw_population_user')",
        ));
        $cascade = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='forwext_giveaway_draws' "
            . "AND CONSTRAINT_NAME='fk_forwext_giveaway_draw_parent' AND DELETE_RULE='CASCADE'",
        ));

        return $table === 1 && $indexes === 3 && $cascade === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Giveaway draw population snapshot or lineage cascade is incomplete.');
    }
}
