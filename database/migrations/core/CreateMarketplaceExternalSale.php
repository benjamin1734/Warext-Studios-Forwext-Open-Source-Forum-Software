<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMarketplaceExternalSale implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260919212000_marketplace_external_sale');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_external_sale_links ('
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'target_url VARCHAR(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'target_host VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(listing_id),KEY idx_forwext_market_external_host(target_host,enabled),'
            . 'CONSTRAINT fk_forwext_market_external_listing FOREIGN KEY(listing_id) '
            . 'REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_external_actor FOREIGN KEY(updated_by_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_marketplace_external_sale_clicks ('
            . 'click_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'listing_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'viewer_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'target_host VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'clicked_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(click_id),'
            . 'KEY idx_forwext_market_external_click_listing(listing_id,clicked_at_utc),'
            . 'KEY idx_forwext_market_external_click_host(target_host,clicked_at_utc),'
            . 'CONSTRAINT fk_forwext_market_external_click_listing FOREIGN KEY(listing_id) '
            . 'REFERENCES forwext_marketplace_listings(listing_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_market_external_click_user FOREIGN KEY(viewer_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_permissions(permission_key,value_type,description,created_at_utc,updated_at_utc) '
            . "VALUES ('marketplace.external_link.use','flag','Use approved external-sale links on marketplace listings.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)'
        ));

        $profiles=[
            'new_user'=>'deny',
            'member'=>'allow',
            'verified'=>'allow',
            'moderator'=>'allow',
            'administrator'=>'allow',
        ];
        foreach($profiles as $template=>$effect){
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                . "VALUES (:template,'marketplace.external_link.use',:effect,NULL) "
                . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                ['template'=>$template,'effect'=>$effect]
            ));
        }
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $tables=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "
            . "('forwext_marketplace_external_sale_links','forwext_marketplace_external_sale_clicks')"
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') "
            . "AND permission_key='marketplace.external_link.use'"
        ));
        return $tables===2&&$rules===5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Marketplace external-sale schema or permission defaults are incomplete.');
    }
}
