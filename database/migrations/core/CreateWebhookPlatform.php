<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateWebhookPlatform implements Migration
{
    public function id():MigrationId
    {
        return MigrationId::fromString('20260925202000_webhook_platform');
    }

    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_webhook_subscriptions ('
            .'subscription_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            .'event_name VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            .'destination_url VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            .'active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            .'secret_version SMALLINT UNSIGNED NOT NULL,previous_secret_version SMALLINT UNSIGNED NULL,'
            .'previous_secret_valid_until_utc DATETIME(6) NULL,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 8,'
            .'created_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            .'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            .'PRIMARY KEY(subscription_id),'
            .'KEY idx_forwext_webhook_event(event_name,active,created_at_utc),'
            .'KEY idx_forwext_webhook_actor(created_by_user_id,created_at_utc),'
            .'CONSTRAINT fk_forwext_webhook_actor FOREIGN KEY(created_by_user_id) '
            .'REFERENCES forwext_users(user_id) ON DELETE SET NULL'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_webhook_deliveries ('
            .'delivery_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            .'subscription_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            .'event_name VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            .'body_json MEDIUMTEXT NOT NULL,is_test TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            .'status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            .'attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL,'
            .'next_attempt_at_utc DATETIME(6) NULL,last_http_status SMALLINT UNSIGNED NULL,'
            .'last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            .'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,delivered_at_utc DATETIME(6) NULL,'
            .'PRIMARY KEY(delivery_id),'
            .'KEY idx_forwext_webhook_delivery_due(status,next_attempt_at_utc,created_at_utc),'
            .'KEY idx_forwext_webhook_delivery_subscription(subscription_id,created_at_utc),'
            .'CONSTRAINT fk_forwext_webhook_delivery_subscription FOREIGN KEY(subscription_id) '
            .'REFERENCES forwext_webhook_subscriptions(subscription_id) ON DELETE CASCADE'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_webhook_delivery_attempts ('
            .'delivery_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            .'attempt_number TINYINT UNSIGNED NOT NULL,result VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            .'http_status SMALLINT UNSIGNED NULL,error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            .'retryable TINYINT(1) UNSIGNED NOT NULL,started_at_utc DATETIME(6) NOT NULL,'
            .'finished_at_utc DATETIME(6) NOT NULL,duration_ms INT UNSIGNED NOT NULL,'
            .'PRIMARY KEY(delivery_id,attempt_number),'
            .'KEY idx_forwext_webhook_attempt_time(finished_at_utc,delivery_id),'
            .'CONSTRAINT fk_forwext_webhook_attempt_delivery FOREIGN KEY(delivery_id) '
            .'REFERENCES forwext_webhook_deliveries(delivery_id) ON DELETE CASCADE'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_permissions '
            .'(permission_key,value_type,description,created_at_utc,updated_at_utc) '
            ."VALUES ('webhook.manage','flag','Manage outbound webhook subscriptions, secrets and delivery tests.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            .'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)',
        ));

        foreach(['new_user','member','verified','moderator','administrator'] as $template){
            $effect=$template==='administrator'?'allow':'deny';
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                ."VALUES (:template,'webhook.manage',:effect,NULL) "
                .'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                ['template'=>$template,'effect'=>$effect],
            ));
        }
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $tables=(int)$context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            ."AND TABLE_NAME IN ('forwext_webhook_subscriptions','forwext_webhook_deliveries','forwext_webhook_delivery_attempts')",
        ));
        $permission=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key='webhook.manage'",
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE permission_key='webhook.manage' "
            ."AND template_key IN ('new_user','member','verified','moderator','administrator')",
        ));

        return $tables===3&&$permission===1&&$rules===5
            ?MigrationVerification::passed()
            :MigrationVerification::failed('Webhook platform schema or permission defaults are incomplete.');
    }
}
