<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSubscriptionUpgradeSystem implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260921220000_subscription_upgrade_system');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_subscription_plans ('
            . 'plan_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'plan_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,name VARCHAR(120) NOT NULL,'
            . 'description TEXT NOT NULL,active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'price_minor BIGINT UNSIGNED NOT NULL,currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'duration_days INT UNSIGNED NULL,sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'created_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(plan_id),UNIQUE KEY uq_forwext_subscription_plan_key(plan_key),'
            . 'KEY idx_forwext_subscription_plan_active(active,sort_order,plan_id),'
            . 'CONSTRAINT fk_forwext_subscription_plan_creator FOREIGN KEY(created_by_user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_subscription_plan_updater FOREIGN KEY(updated_by_user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_subscription_plan_roles ('
            . 'plan_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,role_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY(plan_id,role_id),KEY idx_forwext_subscription_plan_role(role_id,plan_id),'
            . 'CONSTRAINT fk_forwext_subscription_role_plan FOREIGN KEY(plan_id) REFERENCES forwext_subscription_plans(plan_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_subscription_role_role FOREIGN KEY(role_id) REFERENCES forwext_roles(role_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_subscription_plan_permissions ('
            . 'plan_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,permission_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY(plan_id,permission_key),KEY idx_forwext_subscription_plan_permission(permission_key,plan_id),'
            . 'CONSTRAINT fk_forwext_subscription_permission_plan FOREIGN KEY(plan_id) REFERENCES forwext_subscription_plans(plan_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_subscription_permission_definition FOREIGN KEY(permission_key) REFERENCES forwext_permissions(permission_key) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_user_subscriptions ('
            . 'subscription_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'plan_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',"
            . 'starts_at_utc DATETIME(6) NOT NULL,ends_at_utc DATETIME(6) NULL,created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(subscription_id),UNIQUE KEY uq_forwext_user_subscription(user_id,plan_id),'
            . 'KEY idx_forwext_subscription_expiry(state,ends_at_utc,subscription_id),KEY idx_forwext_subscription_plan(plan_id,state,user_id),'
            . 'CONSTRAINT fk_forwext_user_subscription_user FOREIGN KEY(user_id) REFERENCES forwext_users(user_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_user_subscription_plan FOREIGN KEY(plan_id) REFERENCES forwext_subscription_plans(plan_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_subscription_purchases ('
            . 'purchase_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'plan_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'idempotency_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,amount_minor BIGINT UNSIGNED NOT NULL,'
            . 'currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,duration_days INT UNSIGNED NULL,'
            . "state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . 'provider_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,checkout_url VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,activated_at_utc DATETIME(6) NULL,'
            . 'PRIMARY KEY(purchase_id),UNIQUE KEY uq_forwext_subscription_purchase_idem(user_id,plan_id,provider_key,idempotency_key),'
            . 'UNIQUE KEY uq_forwext_subscription_purchase_reference(provider_key,provider_reference),'
            . 'KEY idx_forwext_subscription_purchase_user(user_id,created_at_utc,purchase_id),KEY idx_forwext_subscription_purchase_state(provider_key,state,updated_at_utc),'
            . 'CONSTRAINT fk_forwext_subscription_purchase_user FOREIGN KEY(user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_subscription_purchase_plan FOREIGN KEY(plan_id) REFERENCES forwext_subscription_plans(plan_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_subscription_webhook_events ('
            . 'provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,event_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'purchase_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,occurred_at_utc DATETIME(6) NOT NULL,received_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(provider_key,event_id),KEY idx_forwext_subscription_webhook_purchase(purchase_id,received_at_utc),'
            . 'CONSTRAINT fk_forwext_subscription_webhook_purchase FOREIGN KEY(purchase_id) REFERENCES forwext_subscription_purchases(purchase_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_subscription_events ('
            . 'event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,subscription_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,action VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'purchase_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,from_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'to_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,from_ends_at_utc DATETIME(6) NULL,to_ends_at_utc DATETIME(6) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(event_id),'
            . 'KEY idx_forwext_subscription_event_subscription(subscription_id,created_at_utc,event_id),KEY idx_forwext_subscription_event_purchase(purchase_id),'
            . 'CONSTRAINT fk_forwext_subscription_event_subscription FOREIGN KEY(subscription_id) REFERENCES forwext_user_subscriptions(subscription_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_subscription_event_actor FOREIGN KEY(actor_user_id) REFERENCES forwext_users(user_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_subscription_event_purchase FOREIGN KEY(purchase_id) REFERENCES forwext_subscription_purchases(purchase_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $permissions=[
            'subscription.view'=>'View available subscription and user-upgrade plans.',
            'subscription.purchase'=>'Purchase eligible subscription or user-upgrade plans.',
            'subscription.manage_own'=>'Manage own subscription and upgrade state.',
            'subscription.manage_all'=>'Manage all subscription and user-upgrade plans and assignments.',
        ];
        foreach($permissions as $key=>$description){
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . "VALUES (:permission,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)',
                ['permission'=>$key,'description'=>$description]
            ));
        }
        $profiles=[
            'new_user'=>['subscription.view'=>'deny','subscription.purchase'=>'deny','subscription.manage_own'=>'deny','subscription.manage_all'=>'deny'],
            'member'=>['subscription.view'=>'allow','subscription.purchase'=>'allow','subscription.manage_own'=>'allow','subscription.manage_all'=>'deny'],
            'verified'=>['subscription.view'=>'allow','subscription.purchase'=>'allow','subscription.manage_own'=>'allow','subscription.manage_all'=>'deny'],
            'moderator'=>['subscription.view'=>'allow','subscription.purchase'=>'allow','subscription.manage_own'=>'allow','subscription.manage_all'=>'deny'],
            'administrator'=>['subscription.view'=>'allow','subscription.purchase'=>'allow','subscription.manage_own'=>'allow','subscription.manage_all'=>'allow'],
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
            . "('forwext_subscription_plans','forwext_subscription_plan_roles','forwext_subscription_plan_permissions',"
            . "'forwext_user_subscriptions','forwext_subscription_purchases','forwext_subscription_webhook_events','forwext_subscription_events')"
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') AND permission_key IN "
            . "('subscription.view','subscription.purchase','subscription.manage_own','subscription.manage_all')"
        ));
        return $tables===7&&$rules===20
            ?MigrationVerification::passed()
            :MigrationVerification::failed('Subscription/user-upgrade schema or permission defaults are incomplete.');
    }
}
