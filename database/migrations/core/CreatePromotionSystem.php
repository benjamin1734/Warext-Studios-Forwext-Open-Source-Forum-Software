<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreatePromotionSystem implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260919181000_promotion_system');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_promotions ('
            . 'promotion_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'promotion_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,name VARCHAR(120) NOT NULL,'
            . 'active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,'
            . 'rule_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,threshold BIGINT UNSIGNED NOT NULL,'
            . 'reward_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,units INT UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(promotion_id),'
            . 'UNIQUE KEY uq_forwext_promotion_key(promotion_key),KEY idx_forwext_promotion_active(active,priority,promotion_id),'
            . 'KEY idx_forwext_promotion_rule(rule_type,threshold,active)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_promotion_runtime_state ('
            . 'state_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'cursor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,updated_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(state_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            "INSERT INTO forwext_promotion_runtime_state(state_key,cursor_user_id,updated_at_utc) "
            . "VALUES ('rule_evaluation',NULL,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE state_key=VALUES(state_key)"
        ));
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $tables=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME IN ('forwext_promotions','forwext_promotion_runtime_state')"
        ));
        return $tables===2
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Promotion schema is incomplete.');
    }
}
