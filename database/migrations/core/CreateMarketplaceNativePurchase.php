<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMarketplaceNativePurchase implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260919213000_marketplace_native_purchase');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_internal_sale_settings ('
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(listing_id),'
            . 'KEY idx_forwext_market_internal_sale_enabled(enabled,listing_id),'
            . 'CONSTRAINT fk_forwext_market_internal_sale_listing FOREIGN KEY(listing_id) '
            . 'REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_internal_sale_actor FOREIGN KEY(updated_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_cart_items ('
            . 'buyer_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(buyer_user_id,listing_id),'
            . 'KEY idx_forwext_market_cart_listing(listing_id,buyer_user_id),'
            . 'CONSTRAINT fk_forwext_market_cart_buyer FOREIGN KEY(buyer_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_cart_listing FOREIGN KEY(listing_id) '
            . 'REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_orders ('
            . 'order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'order_number VARCHAR(25) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'checkout_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'buyer_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'seller_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'subtotal_minor BIGINT UNSIGNED NOT NULL,total_minor BIGINT UNSIGNED NOT NULL,'
            . "order_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . "payment_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . "delivery_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . 'billing_json JSON NOT NULL,receipt_json JSON NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(order_id),UNIQUE KEY uq_forwext_market_order_number(order_number),'
            . 'UNIQUE KEY uq_forwext_market_checkout_group(buyer_user_id,checkout_key,seller_user_id,currency),'
            . 'KEY idx_forwext_market_order_buyer(buyer_user_id,created_at_utc,order_id),'
            . 'KEY idx_forwext_market_order_seller(seller_user_id,created_at_utc,order_id),'
            . 'KEY idx_forwext_market_order_states(order_state,payment_state,delivery_state,updated_at_utc),'
            . 'CONSTRAINT fk_forwext_market_order_buyer FOREIGN KEY(buyer_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_market_order_seller FOREIGN KEY(seller_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_order_items ('
            . 'item_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'title VARCHAR(180) NOT NULL,quantity SMALLINT UNSIGNED NOT NULL,'
            . 'unit_minor BIGINT UNSIGNED NOT NULL,currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'line_total_minor BIGINT UNSIGNED NOT NULL,PRIMARY KEY(item_id),'
            . 'KEY idx_forwext_market_order_item_order(order_id,item_id),'
            . 'KEY idx_forwext_market_order_item_listing(listing_id,order_id),'
            . 'CONSTRAINT fk_forwext_market_order_item_order FOREIGN KEY(order_id) '
            . 'REFERENCES forwext_marketplace_orders(order_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_order_item_listing FOREIGN KEY(listing_id) '
            . 'REFERENCES forwext_marketplace_listings(listing_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_order_history ('
            . 'history_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'action VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'from_order_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'to_order_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'from_payment_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'to_payment_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'from_delivery_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'to_delivery_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(history_id),'
            . 'KEY idx_forwext_market_order_history(order_id,created_at_utc,history_id),'
            . 'CONSTRAINT fk_forwext_market_order_history_order FOREIGN KEY(order_id) '
            . 'REFERENCES forwext_marketplace_orders(order_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_order_history_actor FOREIGN KEY(actor_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $permissions=[
            'marketplace.internal_purchase.use'=>'Use native Marketplace purchasing on owned listings.',
            'marketplace.purchase'=>'Purchase eligible Marketplace listings.',
            'marketplace.order.manage'=>'Manage Marketplace orders and lifecycle records.',
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
            'new_user'=>[
                'marketplace.internal_purchase.use'=>'deny','marketplace.purchase'=>'deny','marketplace.order.manage'=>'deny',
            ],
            'member'=>[
                'marketplace.internal_purchase.use'=>'allow','marketplace.purchase'=>'allow','marketplace.order.manage'=>'deny',
            ],
            'verified'=>[
                'marketplace.internal_purchase.use'=>'allow','marketplace.purchase'=>'allow','marketplace.order.manage'=>'deny',
            ],
            'moderator'=>[
                'marketplace.internal_purchase.use'=>'allow','marketplace.purchase'=>'allow','marketplace.order.manage'=>'allow',
            ],
            'administrator'=>[
                'marketplace.internal_purchase.use'=>'allow','marketplace.purchase'=>'allow','marketplace.order.manage'=>'allow',
            ],
        ];
        foreach($profiles as $template=>$rules){
            foreach($rules as $permission=>$effect){
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template,:permission,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    ['template'=>$template,'permission'=>$permission,'effect'=>$effect]
                ));
            }
        }
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $tables=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "
            . "('forwext_marketplace_internal_sale_settings','forwext_marketplace_cart_items','forwext_marketplace_orders',"
            . "'forwext_marketplace_order_items','forwext_marketplace_order_history')"
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') AND permission_key IN "
            . "('marketplace.internal_purchase.use','marketplace.purchase','marketplace.order.manage')"
        ));
        return $tables===5&&$rules===15
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Marketplace native-purchase schema or permission defaults are incomplete.');
    }
}
