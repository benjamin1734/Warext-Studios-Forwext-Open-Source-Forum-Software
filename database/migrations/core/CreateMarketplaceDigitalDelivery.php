<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMarketplaceDigitalDelivery implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260919215000_marketplace_digital_delivery');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_delivery_assets ('
            . 'asset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'storage_path VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'filename VARCHAR(191) NOT NULL,media_type VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'size_bytes BIGINT UNSIGNED NOT NULL,sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(asset_id),UNIQUE KEY uq_forwext_market_delivery_asset_path(storage_path),'
            . 'KEY idx_forwext_market_delivery_asset_listing(listing_id,created_at_utc,asset_id),'
            . 'CONSTRAINT fk_forwext_market_delivery_asset_listing FOREIGN KEY(listing_id) '
            . 'REFERENCES forwext_marketplace_listings(listing_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_market_delivery_asset_actor FOREIGN KEY(created_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_delivery_settings ('
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "delivery_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'manual',"
            . 'asset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(listing_id),KEY idx_forwext_market_delivery_setting_type(delivery_type,listing_id),'
            . 'CONSTRAINT fk_forwext_market_delivery_setting_listing FOREIGN KEY(listing_id) '
            . 'REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_delivery_setting_asset FOREIGN KEY(asset_id) '
            . 'REFERENCES forwext_marketplace_delivery_assets(asset_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_market_delivery_setting_actor FOREIGN KEY(updated_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $deliveryTypeColumn=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_marketplace_order_items' AND COLUMN_NAME='delivery_type'"
        ));
        if($deliveryTypeColumn===0){
            $context->execute(new CompiledQuery(
                "ALTER TABLE forwext_marketplace_order_items ADD COLUMN delivery_type "
                . "VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'manual' AFTER line_total_minor"
            ));
        }

        $deliveryAssetColumn=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_marketplace_order_items' AND COLUMN_NAME='delivery_asset_id'"
        ));
        if($deliveryAssetColumn===0){
            $context->execute(new CompiledQuery(
                'ALTER TABLE forwext_marketplace_order_items ADD COLUMN delivery_asset_id '
                . 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER delivery_type'
            ));
        }

        $assetIndex=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_marketplace_order_items' AND INDEX_NAME='idx_forwext_market_order_item_delivery_asset'"
        ));
        if($assetIndex===0){
            $context->execute(new CompiledQuery(
                'ALTER TABLE forwext_marketplace_order_items ADD KEY '
                . 'idx_forwext_market_order_item_delivery_asset(delivery_asset_id)'
            ));
        }

        $assetFk=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_marketplace_order_items' "
            . "AND CONSTRAINT_NAME='fk_forwext_market_order_item_delivery_asset' AND CONSTRAINT_TYPE='FOREIGN KEY'"
        ));
        if($assetFk===0){
            $context->execute(new CompiledQuery(
                'ALTER TABLE forwext_marketplace_order_items ADD CONSTRAINT fk_forwext_market_order_item_delivery_asset '
                . 'FOREIGN KEY(delivery_asset_id) REFERENCES forwext_marketplace_delivery_assets(asset_id) ON DELETE RESTRICT'
            ));
        }

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_delivery_keys ('
            . 'key_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'encrypted_value MEDIUMTEXT NOT NULL,fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "key_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'available',"
            . 'assigned_order_item_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,assigned_at_utc DATETIME(6) NULL,'
            . 'PRIMARY KEY(key_id),UNIQUE KEY uq_forwext_market_delivery_key_fingerprint(listing_id,fingerprint),'
            . 'UNIQUE KEY uq_forwext_market_delivery_key_assignment(assigned_order_item_id),'
            . 'KEY idx_forwext_market_delivery_key_pool(listing_id,key_state,created_at_utc,key_id),'
            . 'CONSTRAINT fk_forwext_market_delivery_key_listing FOREIGN KEY(listing_id) '
            . 'REFERENCES forwext_marketplace_listings(listing_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_market_delivery_key_item FOREIGN KEY(assigned_order_item_id) '
            . 'REFERENCES forwext_marketplace_order_items(item_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_market_delivery_key_actor FOREIGN KEY(created_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_order_item_deliveries ('
            . 'order_item_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'order_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'delivery_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "delivery_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',"
            . 'asset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'key_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,encrypted_manual_value MEDIUMTEXT NULL,'
            . 'fulfilled_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'download_count INT UNSIGNED NOT NULL DEFAULT 0,ready_at_utc DATETIME(6) NULL,'
            . 'delivered_at_utc DATETIME(6) NULL,last_download_at_utc DATETIME(6) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(order_item_id),KEY idx_forwext_market_item_delivery_order(order_id,delivery_state,order_item_id),'
            . 'KEY idx_forwext_market_item_delivery_asset(asset_id),KEY idx_forwext_market_item_delivery_key(key_id),'
            . 'CONSTRAINT fk_forwext_market_item_delivery_item FOREIGN KEY(order_item_id) '
            . 'REFERENCES forwext_marketplace_order_items(item_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_item_delivery_order FOREIGN KEY(order_id) '
            . 'REFERENCES forwext_marketplace_orders(order_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_item_delivery_asset FOREIGN KEY(asset_id) '
            . 'REFERENCES forwext_marketplace_delivery_assets(asset_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_market_item_delivery_key FOREIGN KEY(key_id) '
            . 'REFERENCES forwext_marketplace_delivery_keys(key_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_market_item_delivery_actor FOREIGN KEY(fulfilled_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_order_item_deliveries '
            . '(order_item_id,order_id,delivery_type,delivery_state,asset_id,key_id,encrypted_manual_value,'
            . 'fulfilled_by_user_id,download_count,ready_at_utc,delivered_at_utc,last_download_at_utc,created_at_utc,updated_at_utc) '
            . "SELECT i.item_id,i.order_id,COALESCE(i.delivery_type,'manual'),'pending',i.delivery_asset_id,NULL,NULL,NULL,"
            . '0,NULL,NULL,NULL,o.created_at_utc,o.updated_at_utc FROM forwext_marketplace_order_items i '
            . 'INNER JOIN forwext_marketplace_orders o ON o.order_id=i.order_id '
            . 'LEFT JOIN forwext_marketplace_order_item_deliveries d ON d.order_item_id=i.item_id '
            . 'WHERE d.order_item_id IS NULL'
        ));

        $permissions=[
            'marketplace.delivery.manage_own'=>'Configure and fulfill delivery for owned marketplace listings and sales.',
            'marketplace.delivery.manage_all'=>'Manage marketplace delivery configuration and fulfillment for all sellers.',
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
            'new_user'=>['marketplace.delivery.manage_own'=>'deny','marketplace.delivery.manage_all'=>'deny'],
            'member'=>['marketplace.delivery.manage_own'=>'allow','marketplace.delivery.manage_all'=>'deny'],
            'verified'=>['marketplace.delivery.manage_own'=>'allow','marketplace.delivery.manage_all'=>'deny'],
            'moderator'=>['marketplace.delivery.manage_own'=>'allow','marketplace.delivery.manage_all'=>'allow'],
            'administrator'=>['marketplace.delivery.manage_own'=>'allow','marketplace.delivery.manage_all'=>'allow'],
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
            . "('forwext_marketplace_delivery_assets','forwext_marketplace_delivery_settings',"
            . "'forwext_marketplace_delivery_keys','forwext_marketplace_order_item_deliveries')"
        ));
        $columns=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_marketplace_order_items' AND COLUMN_NAME IN ('delivery_type','delivery_asset_id')"
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') AND permission_key IN "
            . "('marketplace.delivery.manage_own','marketplace.delivery.manage_all')"
        ));
        $missing=(int)$context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_marketplace_order_items i '
            . 'LEFT JOIN forwext_marketplace_order_item_deliveries d ON d.order_item_id=i.item_id '
            . 'WHERE d.order_item_id IS NULL'
        ));

        return $tables===4&&$columns===2&&$rules===10&&$missing===0
            ?MigrationVerification::passed()
            :MigrationVerification::failed('Marketplace digital-delivery schema or permission defaults are incomplete.');
    }
}
