<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMarketplaceDiscoveryUx implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260919211000_marketplace_discovery_ux');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_listing_promotions ('
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,featured_until_utc DATETIME(6) NULL,pinned_until_utc DATETIME(6) NULL,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(listing_id),KEY idx_forwext_market_promotion_featured(featured_until_utc,listing_id),'
            . 'KEY idx_forwext_market_promotion_pinned(pinned_until_utc,listing_id),'
            . 'CONSTRAINT fk_forwext_market_promotion_listing FOREIGN KEY(listing_id) REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_promotion_actor FOREIGN KEY(updated_by_user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_reviews ('
            . 'review_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'reviewer_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,rating TINYINT UNSIGNED NOT NULL,body VARCHAR(5000) NOT NULL,'
            . "state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'visible',"
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(review_id),'
            . 'UNIQUE KEY uq_forwext_market_review_user(listing_id,reviewer_user_id),'
            . 'KEY idx_forwext_market_review_public(listing_id,state,updated_at_utc),'
            . 'CONSTRAINT fk_forwext_market_review_listing FOREIGN KEY(listing_id) REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_review_user FOREIGN KEY(reviewer_user_id) REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $priceIndex=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_marketplace_listings' AND INDEX_NAME='idx_forwext_market_listing_price'"
        ));
        if($priceIndex===0){
            $context->execute(new CompiledQuery(
                'CREATE INDEX idx_forwext_market_listing_price ON forwext_marketplace_listings(state,currency,price_minor,updated_at_utc)'
            ));
        }

        $permissions=[
            'marketplace.feature.manage'=>'Manage featured and pinned marketplace placement.',
            'marketplace.review.create'=>'Create or update marketplace reviews.',
            'marketplace.review.manage'=>'Moderate marketplace reviews.',
        ];
        foreach($permissions as $key=>$description){
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . 'VALUES (:key,\'flag\',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)',
                ['key'=>$key,'description'=>$description]
            ));
        }
        $profiles=[
            'new_user'=>['marketplace.feature.manage'=>'deny','marketplace.review.create'=>'deny','marketplace.review.manage'=>'deny'],
            'member'=>['marketplace.feature.manage'=>'deny','marketplace.review.create'=>'allow','marketplace.review.manage'=>'deny'],
            'verified'=>['marketplace.feature.manage'=>'deny','marketplace.review.create'=>'allow','marketplace.review.manage'=>'deny'],
            'moderator'=>['marketplace.feature.manage'=>'deny','marketplace.review.create'=>'allow','marketplace.review.manage'=>'allow'],
            'administrator'=>['marketplace.feature.manage'=>'allow','marketplace.review.create'=>'allow','marketplace.review.manage'=>'allow'],
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
            . "SELECT user_id,'marketplace',1,'public',28 FROM forwext_user_profiles"
        ));
    }

        $context->execute(new CompiledQuery(
            "INSERT INTO forwext_search_index_changes(document_type,document_id,revision,attempts,available_at_utc,locked_until_utc,last_error_code,updated_at_utc) "
            . "SELECT 'marketplace.listing',listing_id,1,0,UTC_TIMESTAMP(6),NULL,NULL,UTC_TIMESTAMP(6) "
            . "FROM forwext_marketplace_listings WHERE state IN ('active','sold') "
            . "ON DUPLICATE KEY UPDATE revision=revision+1,attempts=0,available_at_utc=UTC_TIMESTAMP(6),"
            . "locked_until_utc=NULL,last_error_code=NULL,updated_at_utc=UTC_TIMESTAMP(6)"
        ));
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $tables=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME IN ('forwext_marketplace_listing_promotions','forwext_marketplace_reviews')"
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') AND permission_key IN "
            . "('marketplace.feature.manage','marketplace.review.create','marketplace.review.manage')"
        ));
        return $tables===2&&$rules===15
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Marketplace discovery UX schema or permission defaults are incomplete.');
    }
}
