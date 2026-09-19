<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMarketplaceDomain implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260919210000_marketplace_domain');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_categories ('
            . 'category_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,parent_category_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,slug VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'name VARCHAR(120) NOT NULL,description VARCHAR(4000) NOT NULL,enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(category_id),UNIQUE KEY uq_forwext_market_category_key(category_key),UNIQUE KEY uq_forwext_market_category_slug(slug),'
            . 'KEY idx_forwext_market_category_parent(parent_category_id,enabled,sort_order),'
            . 'CONSTRAINT fk_forwext_market_category_parent FOREIGN KEY(parent_category_id) REFERENCES forwext_marketplace_categories(category_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_listings ('
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,seller_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'category_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,slug VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'title VARCHAR(180) NOT NULL,description MEDIUMTEXT NOT NULL,price_minor BIGINT UNSIGNED NOT NULL,currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(listing_id),UNIQUE KEY uq_forwext_market_listing_slug(slug),'
            . 'KEY idx_forwext_market_listing_public(state,category_id,updated_at_utc),KEY idx_forwext_market_listing_seller(seller_user_id,state,updated_at_utc),'
            . 'CONSTRAINT fk_forwext_market_listing_seller FOREIGN KEY(seller_user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_market_listing_category FOREIGN KEY(category_id) REFERENCES forwext_marketplace_categories(category_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_listing_tags ('
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,tag_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY(listing_id,tag_key),KEY idx_forwext_market_tag(tag_key,listing_id),'
            . 'CONSTRAINT fk_forwext_market_tag_listing FOREIGN KEY(listing_id) REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_listing_media ('
            . 'media_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'storage_path VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,media_type VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'alt_text VARCHAR(500) NOT NULL,sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(media_id),'
            . 'KEY idx_forwext_market_media_listing(listing_id,sort_order,media_id),'
            . 'CONSTRAINT fk_forwext_market_media_listing FOREIGN KEY(listing_id) REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_custom_fields ('
            . 'field_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,category_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'field_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,label VARCHAR(120) NOT NULL,'
            . 'field_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,required TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . 'options_json JSON NOT NULL,sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(field_id),'
            . 'UNIQUE KEY uq_forwext_market_field(category_id,field_key),KEY idx_forwext_market_field_active(category_id,active,sort_order),'
            . 'CONSTRAINT fk_forwext_market_field_category FOREIGN KEY(category_id) REFERENCES forwext_marketplace_categories(category_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_listing_custom_values ('
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,field_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,value_json JSON NOT NULL,'
            . 'PRIMARY KEY(listing_id,field_id),CONSTRAINT fk_forwext_market_value_listing FOREIGN KEY(listing_id) REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_value_field FOREIGN KEY(field_id) REFERENCES forwext_marketplace_custom_fields(field_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_history ('
            . 'history_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,action VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'from_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,to_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(history_id),KEY idx_forwext_market_history_listing(listing_id,created_at_utc),'
            . 'CONSTRAINT fk_forwext_market_history_listing FOREIGN KEY(listing_id) REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_history_actor FOREIGN KEY(actor_user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_permissions(permission_key,value_type,description,created_at_utc,updated_at_utc) '
            . "VALUES ('marketplace.category.manage','flag','Manage marketplace categories and custom fields.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)'
        ));

        $profiles=[
            'new_user'=>['marketplace.category.manage'=>'deny','marketplace.listing.view'=>'allow','marketplace.listing.create'=>'deny','marketplace.listing.manage_own'=>'deny','marketplace.listing.manage_all'=>'deny'],
            'member'=>['marketplace.category.manage'=>'deny','marketplace.listing.view'=>'allow','marketplace.listing.create'=>'allow','marketplace.listing.manage_own'=>'allow','marketplace.listing.manage_all'=>'deny'],
            'verified'=>['marketplace.category.manage'=>'deny','marketplace.listing.view'=>'allow','marketplace.listing.create'=>'allow','marketplace.listing.manage_own'=>'allow','marketplace.listing.manage_all'=>'deny'],
            'moderator'=>['marketplace.category.manage'=>'deny','marketplace.listing.view'=>'allow','marketplace.listing.create'=>'allow','marketplace.listing.manage_own'=>'allow','marketplace.listing.manage_all'=>'allow'],
            'administrator'=>['marketplace.category.manage'=>'allow','marketplace.listing.view'=>'allow','marketplace.listing.create'=>'allow','marketplace.listing.manage_own'=>'allow','marketplace.listing.manage_all'=>'allow'],
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
            . "('forwext_marketplace_categories','forwext_marketplace_listings','forwext_marketplace_listing_tags','forwext_marketplace_listing_media',"
            . "'forwext_marketplace_custom_fields','forwext_marketplace_listing_custom_values','forwext_marketplace_history')"
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') AND permission_key IN "
            . "('marketplace.category.manage','marketplace.listing.view','marketplace.listing.create','marketplace.listing.manage_own','marketplace.listing.manage_all')"
        ));
        return $tables===7&&$rules===25
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Marketplace domain schema or permission defaults are incomplete.');
    }
}
