<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreatePaymentAbstraction implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260919214000_payment_abstraction');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_payment_attempts ('
            . 'attempt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'buyer_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'idempotency_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'amount_minor BIGINT UNSIGNED NOT NULL,currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . 'provider_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'checkout_url VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(attempt_id),'
            . 'UNIQUE KEY uq_forwext_payment_attempt_idempotency(order_id,provider_key,idempotency_key),'
            . 'UNIQUE KEY uq_forwext_payment_provider_reference(provider_key,provider_reference),'
            . 'KEY idx_forwext_payment_attempt_buyer(buyer_user_id,created_at_utc,attempt_id),'
            . 'KEY idx_forwext_payment_attempt_state(provider_key,state,updated_at_utc),'
            . 'CONSTRAINT fk_forwext_payment_attempt_order FOREIGN KEY(order_id) '
            . 'REFERENCES forwext_marketplace_orders(order_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_payment_attempt_buyer FOREIGN KEY(buyer_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_payment_webhook_events ('
            . 'provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'event_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'attempt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'occurred_at_utc DATETIME(6) NOT NULL,received_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(provider_key,event_id),'
            . 'KEY idx_forwext_payment_webhook_attempt(attempt_id,received_at_utc),'
            . 'CONSTRAINT fk_forwext_payment_webhook_attempt FOREIGN KEY(attempt_id) '
            . 'REFERENCES forwext_payment_attempts(attempt_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_payment_refunds ('
            . 'refund_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'attempt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'idempotency_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'amount_minor BIGINT UNSIGNED NOT NULL,'
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . 'provider_refund_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(refund_id),UNIQUE KEY uq_forwext_payment_refund_idempotency(attempt_id,idempotency_key),'
            . 'UNIQUE KEY uq_forwext_payment_refund_reference(provider_refund_reference),'
            . 'KEY idx_forwext_payment_refund_attempt(attempt_id,created_at_utc,refund_id),'
            . 'CONSTRAINT fk_forwext_payment_refund_attempt FOREIGN KEY(attempt_id) '
            . 'REFERENCES forwext_payment_attempts(attempt_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_payment_refund_actor FOREIGN KEY(actor_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $historyActorNullable=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_marketplace_order_history' AND COLUMN_NAME='actor_user_id' AND IS_NULLABLE='YES'"
        ));
        if($historyActorNullable===0){
            $context->execute(new CompiledQuery(
                'ALTER TABLE forwext_marketplace_order_history MODIFY actor_user_id '
                . 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL'
            ));
        }

        $permissions=[
            'payment.manage'=>'Manage payment-provider configuration and payment operations.',
            'payment.refund'=>'Issue authorized refunds or payment cancellations.',
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
            'new_user'=>['payment.manage'=>'deny','payment.refund'=>'deny'],
            'member'=>['payment.manage'=>'deny','payment.refund'=>'deny'],
            'verified'=>['payment.manage'=>'deny','payment.refund'=>'deny'],
            'moderator'=>['payment.manage'=>'deny','payment.refund'=>'deny'],
            'administrator'=>['payment.manage'=>'allow','payment.refund'=>'allow'],
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
            . "('forwext_payment_attempts','forwext_payment_webhook_events','forwext_payment_refunds')"
        ));
        $historyActorNullable=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_marketplace_order_history' AND COLUMN_NAME='actor_user_id' AND IS_NULLABLE='YES'"
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') AND permission_key IN ('payment.manage','payment.refund')"
        ));
        return $tables===3&&$historyActorNullable===1&&$rules===10
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Payment abstraction schema or permission defaults are incomplete.');
    }
}
