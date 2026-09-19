<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateRewardPromotionSystem implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260919180000_reward_promotion_system');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_reward_definitions ('
            . 'reward_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'reward_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,name VARCHAR(120) NOT NULL,'
            . 'active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'target_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(reward_id),UNIQUE KEY uq_forwext_reward_key(reward_key),KEY idx_forwext_reward_provider(provider_key,target_id,active)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_reward_grants ('
            . 'grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,definition_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'recipient_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'source_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,source_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'reward_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,units INT UNSIGNED NOT NULL,'
            . 'state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'target_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,failure_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,applied_at_utc DATETIME(6) NULL,revoked_at_utc DATETIME(6) NULL,'
            . 'PRIMARY KEY(grant_id),UNIQUE KEY uq_forwext_reward_source(recipient_user_id,source_type,source_id,reward_key),'
            . 'KEY idx_forwext_reward_retry(state,created_at_utc),KEY idx_forwext_reward_entitlement(recipient_user_id,provider_key,target_id,state),'
            . 'CONSTRAINT fk_forwext_reward_grant_definition FOREIGN KEY(definition_id) REFERENCES forwext_reward_definitions(reward_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_reward_grant_user FOREIGN KEY(recipient_user_id) REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_reward_assignment_ownership ('
            . 'recipient_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,target_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'managed_by_reward TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(recipient_user_id,provider_key,target_id),'
            . 'CONSTRAINT fk_forwext_reward_ownership_user FOREIGN KEY(recipient_user_id) REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_reward_bindings ('
            . 'binding_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,source_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'source_definition_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,reward_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'units INT UNSIGNED NOT NULL DEFAULT 1,active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,PRIMARY KEY(binding_id),'
            . 'UNIQUE KEY uq_forwext_reward_binding(source_type,source_definition_id,reward_key),'
            . 'KEY idx_forwext_reward_binding_active(source_type,source_definition_id,active)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $profiles=[
            'new_user'=>['promotion.manage'=>'deny','reward.manage'=>'deny'],
            'member'=>['promotion.manage'=>'deny','reward.manage'=>'deny'],
            'verified'=>['promotion.manage'=>'deny','reward.manage'=>'deny'],
            'moderator'=>['promotion.manage'=>'deny','reward.manage'=>'deny'],
            'administrator'=>['promotion.manage'=>'allow','reward.manage'=>'allow'],
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
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $tables=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "
            . "('forwext_reward_definitions','forwext_reward_grants','forwext_reward_assignment_ownership','forwext_reward_bindings')"
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') AND permission_key IN ('promotion.manage','reward.manage')"
        ));
        return $tables===4&&$rules===10
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Reward provider schema or promotion/reward permission defaults are incomplete.');
    }
}
