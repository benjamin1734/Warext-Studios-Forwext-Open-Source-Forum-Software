<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAdvertisingNoticeSystem implements Migration
{
    public function id():MigrationId{return MigrationId::fromString('20260921223000_advertising_notice_system');}
    public function owner():MigrationOwner{return MigrationOwner::core();}
    public function isIdempotent():bool{return true;}
    public function isTransactional():bool{return false;}

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_ad_campaigns ('
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'campaign_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'advertisement',"
            . 'name VARCHAR(120) NOT NULL,headline VARCHAR(160) NOT NULL,body TEXT NOT NULL,'
            . 'destination_url VARCHAR(2048) NULL,placement_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,priority SMALLINT NOT NULL DEFAULT 0,'
            . 'frequency_cap INT UNSIGNED NULL,frequency_window_seconds INT UNSIGNED NULL,'
            . 'impression_value_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,click_value_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . "currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'TRY',"
            . 'starts_at_utc DATETIME(6) NULL,ends_at_utc DATETIME(6) NULL,'
            . 'created_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(campaign_id),UNIQUE KEY uq_forwext_ad_campaign_key(campaign_key),'
            . 'KEY idx_forwext_ad_active(placement_key,enabled,starts_at_utc,ends_at_utc,priority,campaign_id),'
            . 'CONSTRAINT fk_forwext_ad_creator FOREIGN KEY(created_by_user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_ad_updater FOREIGN KEY(updated_by_user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_ad_route_targets ('
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'route_pattern VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY(campaign_id,route_pattern),'
            . 'CONSTRAINT fk_forwext_ad_route_campaign FOREIGN KEY(campaign_id) REFERENCES forwext_ad_campaigns(campaign_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_ad_forum_targets ('
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'forum_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY(campaign_id,forum_id),'
            . 'CONSTRAINT fk_forwext_ad_forum_campaign FOREIGN KEY(campaign_id) REFERENCES forwext_ad_campaigns(campaign_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_ad_group_targets ('
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'group_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY(campaign_id,group_id),'
            . 'CONSTRAINT fk_forwext_ad_group_campaign FOREIGN KEY(campaign_id) REFERENCES forwext_ad_campaigns(campaign_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_ad_device_targets ('
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'device VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY(campaign_id,device),'
            . 'CONSTRAINT fk_forwext_ad_device_campaign FOREIGN KEY(campaign_id) REFERENCES forwext_ad_campaigns(campaign_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_ad_events ('
            . 'event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "event_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . 'viewer_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'route_name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'forum_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'device VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'occurred_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(event_id),'
            . 'KEY idx_forwext_ad_frequency(campaign_id,event_type,viewer_hash,occurred_at_utc),'
            . 'KEY idx_forwext_ad_analytics(campaign_id,occurred_at_utc,event_type),'
            . 'CONSTRAINT fk_forwext_ad_event_campaign FOREIGN KEY(campaign_id) REFERENCES forwext_ad_campaigns(campaign_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));

        $permissions=[
            'ads.manage'=>'Manage advertising placements, targeting and frequency controls.',
            'notice.manage'=>'Manage site notices and announcements.',
        ];
        foreach($permissions as $key=>$description){
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . "VALUES (:permission,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)',
                ['permission'=>$key,'description'=>$description]
            ));
        }

        foreach(['new_user','member','verified','moderator'] as $template){
            foreach(['ads.manage','notice.manage'] as $permission){
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                    . "VALUES (:template,:permission,'deny',NULL) ON DUPLICATE KEY UPDATE effect='deny',numeric_limit=NULL",
                    ['template'=>$template,'permission'=>$permission]
                ));
            }
        }
        foreach(['ads.manage','notice.manage'] as $permission){
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                . "VALUES ('administrator',:permission,'allow',NULL) ON DUPLICATE KEY UPDATE effect='allow',numeric_limit=NULL",
                ['permission'=>$permission]
            ));
        }
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $tables=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "
            . "('forwext_ad_campaigns','forwext_ad_route_targets','forwext_ad_forum_targets',"
            . "'forwext_ad_group_targets','forwext_ad_device_targets','forwext_ad_events')"
        ));
        $rules=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') AND permission_key IN ('ads.manage','notice.manage')"
        ));
        return $tables===6&&$rules===10
            ?MigrationVerification::passed()
            :MigrationVerification::failed('Advertising/notice schema or permission defaults are incomplete.');
    }
}
