<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateTrophySystem implements Migration
{
    public function id():MigrationId
    {
        return MigrationId::fromString('20260919170000_trophy_system');
    }

    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_trophies ('
            . 'trophy_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'trophy_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'name VARCHAR(120) NOT NULL,description VARCHAR(2000) NOT NULL,'
            . "kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . 'active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,'
            . 'icon_path VARCHAR(512) NULL,banner_path VARCHAR(512) NULL,'
            . "rule_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'manual',"
            . 'threshold BIGINT UNSIGNED NULL,created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(trophy_id),UNIQUE KEY uq_forwext_trophy_key(trophy_key),'
            . 'KEY idx_forwext_trophy_active(active,priority,trophy_id),KEY idx_forwext_trophy_rule(rule_type,active,threshold)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_user_trophies ('
            . 'grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'trophy_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'source VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'awarded_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,awarded_at_utc DATETIME(6) NOT NULL,'
            . 'revoked_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,revoked_at_utc DATETIME(6) NULL,'
            . 'reason VARCHAR(500) NULL,PRIMARY KEY(grant_id),UNIQUE KEY uq_forwext_user_trophy(trophy_id,user_id),'
            . 'KEY idx_forwext_user_trophy_user(user_id,revoked_at_utc,awarded_at_utc),'
            . 'CONSTRAINT fk_forwext_user_trophy_definition FOREIGN KEY(trophy_id) REFERENCES forwext_trophies(trophy_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_user_trophy_user FOREIGN KEY(user_id) REFERENCES forwext_users(user_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_user_trophy_awarder FOREIGN KEY(awarded_by_user_id) REFERENCES forwext_users(user_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_user_trophy_revoker FOREIGN KEY(revoked_by_user_id) REFERENCES forwext_users(user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_trophy_history ('
            . 'history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'trophy_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "action VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . 'source VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'reason VARCHAR(500) NULL,occurred_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(history_id),'
            . 'KEY idx_forwext_trophy_history_user(user_id,history_id),KEY idx_forwext_trophy_history_trophy(trophy_id,history_id),'
            . 'CONSTRAINT fk_forwext_trophy_history_grant FOREIGN KEY(grant_id) REFERENCES forwext_user_trophies(grant_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_trophy_history_trophy FOREIGN KEY(trophy_id) REFERENCES forwext_trophies(trophy_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_trophy_history_user FOREIGN KEY(user_id) REFERENCES forwext_users(user_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_trophy_history_actor FOREIGN KEY(actor_user_id) REFERENCES forwext_users(user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_trophy_runtime_state ('
            . 'state_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'cursor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,updated_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(state_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            "INSERT INTO forwext_trophy_runtime_state(state_key,cursor_user_id,updated_at_utc) "
            . "VALUES ('rule_evaluation',NULL,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE state_key=VALUES(state_key)"
        ));

        $profiles=[
            'new_user'=>['trophy.view'=>'deny','trophy.manage'=>'deny','trophy.award'=>'deny'],
            'member'=>['trophy.view'=>'allow','trophy.manage'=>'deny','trophy.award'=>'deny'],
            'verified'=>['trophy.view'=>'allow','trophy.manage'=>'deny','trophy.award'=>'deny'],
            'moderator'=>['trophy.view'=>'allow','trophy.manage'=>'deny','trophy.award'=>'allow'],
            'administrator'=>['trophy.view'=>'allow','trophy.manage'=>'allow','trophy.award'=>'allow'],
        ];
        foreach($profiles as $template=>$rules){
            foreach($rules as $permission=>$effect){
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template,:permission,:effect,NULL) ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    ['template'=>$template,'permission'=>$permission,'effect'=>$effect]
                ));
            }
        }

        $context->execute(new CompiledQuery(
            "INSERT IGNORE INTO forwext_user_profile_tabs(user_id,tab_key,enabled,visibility,sort_order) "
            . "SELECT user_id,'achievements',1,'public',25 FROM forwext_user_profiles"
        ));
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $tables=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "
            . "('forwext_trophies','forwext_user_trophies','forwext_trophy_history','forwext_trophy_runtime_state')"
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') AND permission_key IN "
            . "('trophy.view','trophy.manage','trophy.award')"
        ));
        return $tables===4&&$rules===15
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Trophy schema or permission defaults are incomplete.');
    }
}
