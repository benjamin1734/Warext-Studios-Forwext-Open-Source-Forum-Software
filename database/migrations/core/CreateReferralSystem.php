<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateReferralSystem implements Migration
{
    private const PERMISSIONS = [
        'invite.create',
        'invite.view_own',
        'invite.manage',
        'referral.view_own',
        'referral.manage',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919140000_referral_system');
    }

    public function owner(): MigrationOwner
    {
        return MigrationOwner::core();
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(MigrationContext $context): void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_referral_campaigns ('
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'campaign_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'name VARCHAR(120) NOT NULL,active TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . 'starts_at_utc DATETIME(6) NOT NULL,ends_at_utc DATETIME(6) NULL,'
            . 'qualification_delay_seconds INT UNSIGNED NOT NULL DEFAULT 86400,'
            . 'attribution_window_seconds INT UNSIGNED NOT NULL DEFAULT 2592000,'
            . 'duplicate_network_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,'
            . 'duplicate_device_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,'
            . 'max_qualified_per_referrer INT UNSIGNED NULL,'
            . 'reward_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'referral.credit\','
            . 'reward_units INT UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(campaign_id),UNIQUE KEY uq_forwext_referral_campaign_key(campaign_key),'
            . 'KEY idx_forwext_referral_campaign_active(active,starts_at_utc,ends_at_utc)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_referral_links ('
            . 'link_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'expires_at_utc DATETIME(6) NULL,disabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(link_id),UNIQUE KEY uq_forwext_referral_code(code),'
            . 'UNIQUE KEY uq_forwext_referral_owner_campaign(campaign_id,owner_user_id),'
            . 'KEY idx_forwext_referral_link_owner(owner_user_id,disabled,created_at_utc),'
            . 'CONSTRAINT fk_forwext_referral_link_campaign FOREIGN KEY(campaign_id) '
            . 'REFERENCES forwext_referral_campaigns(campaign_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_referral_link_owner FOREIGN KEY(owner_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_referral_clicks ('
            . 'click_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'link_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'referrer_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'clicked_at_utc DATETIME(6) NOT NULL,PRIMARY KEY(click_id),'
            . 'KEY idx_forwext_referral_click_campaign(campaign_id,clicked_at_utc),'
            . 'KEY idx_forwext_referral_click_owner(referrer_user_id,clicked_at_utc),'
            . 'CONSTRAINT fk_forwext_referral_click_link FOREIGN KEY(link_id) '
            . 'REFERENCES forwext_referral_links(link_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_referral_click_campaign FOREIGN KEY(campaign_id) '
            . 'REFERENCES forwext_referral_campaigns(campaign_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_referral_click_owner FOREIGN KEY(referrer_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_referral_attributions ('
            . 'attribution_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'link_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'referrer_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'referred_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'risk_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'ip_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'device_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'attributed_at_utc DATETIME(6) NOT NULL,eligible_at_utc DATETIME(6) NOT NULL,'
            . 'qualified_at_utc DATETIME(6) NULL,reviewed_at_utc DATETIME(6) NULL,'
            . 'PRIMARY KEY(attribution_id),UNIQUE KEY uq_forwext_referral_referred(referred_user_id),'
            . 'KEY idx_forwext_referral_attribution_owner(referrer_user_id,state,eligible_at_utc),'
            . 'KEY idx_forwext_referral_attribution_campaign(campaign_id,state,eligible_at_utc),'
            . 'KEY idx_forwext_referral_attribution_ip(campaign_id,referrer_user_id,ip_fingerprint),'
            . 'KEY idx_forwext_referral_attribution_device(campaign_id,referrer_user_id,device_fingerprint),'
            . 'CONSTRAINT fk_forwext_referral_attr_campaign FOREIGN KEY(campaign_id) '
            . 'REFERENCES forwext_referral_campaigns(campaign_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_referral_attr_link FOREIGN KEY(link_id) '
            . 'REFERENCES forwext_referral_links(link_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_referral_attr_referrer FOREIGN KEY(referrer_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_referral_attr_referred FOREIGN KEY(referred_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_referral_rewards ('
            . 'reward_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'attribution_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'campaign_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'recipient_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'reward_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'units INT UNSIGNED NOT NULL,state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'granted_at_utc DATETIME(6) NOT NULL,revoked_at_utc DATETIME(6) NULL,'
            . 'PRIMARY KEY(reward_id),UNIQUE KEY uq_forwext_referral_reward_attribution(attribution_id),'
            . 'KEY idx_forwext_referral_reward_user(recipient_user_id,state,granted_at_utc),'
            . 'KEY idx_forwext_referral_reward_campaign(campaign_id,state,granted_at_utc),'
            . 'CONSTRAINT fk_forwext_referral_reward_attr FOREIGN KEY(attribution_id) '
            . 'REFERENCES forwext_referral_attributions(attribution_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_referral_reward_campaign FOREIGN KEY(campaign_id) '
            . 'REFERENCES forwext_referral_campaigns(campaign_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_referral_reward_user FOREIGN KEY(recipient_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            "INSERT IGNORE INTO forwext_referral_campaigns "
            . "(campaign_id,campaign_key,name,active,starts_at_utc,ends_at_utc,qualification_delay_seconds,"
            . "attribution_window_seconds,duplicate_network_limit,duplicate_device_limit,max_qualified_per_referrer,"
            . "reward_key,reward_units,created_at_utc,updated_at_utc) VALUES "
            . "(LOWER(HEX(RANDOM_BYTES(16))),'community','Topluluk Davet Programı',0,UTC_TIMESTAMP(6),NULL,86400,"
            . "2592000,1,1,NULL,'referral.credit',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
        ));

        $profiles = [
            'new_user' => [
                'invite.create'=>'deny','invite.view_own'=>'deny','invite.manage'=>'deny',
                'referral.view_own'=>'deny','referral.manage'=>'deny',
            ],
            'member' => [
                'invite.create'=>'allow','invite.view_own'=>'allow','invite.manage'=>'deny',
                'referral.view_own'=>'allow','referral.manage'=>'deny',
            ],
            'verified' => [
                'invite.create'=>'allow','invite.view_own'=>'allow','invite.manage'=>'deny',
                'referral.view_own'=>'allow','referral.manage'=>'deny',
            ],
            'moderator' => [
                'invite.create'=>'allow','invite.view_own'=>'allow','invite.manage'=>'allow',
                'referral.view_own'=>'allow','referral.manage'=>'allow',
            ],
            'administrator' => [
                'invite.create'=>'allow','invite.view_own'=>'allow','invite.manage'=>'allow',
                'referral.view_own'=>'allow','referral.manage'=>'allow',
            ],
        ];
        foreach ($profiles as $template => $rules) {
            foreach ($rules as $permission => $effect) {
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template,:permission,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    ['template'=>$template,'permission'=>$permission,'effect'=>$effect],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_referral_campaigns','forwext_referral_links','forwext_referral_clicks',"
            . "'forwext_referral_attributions','forwext_referral_rewards')",
        ));
        $rules = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('invite.create','invite.view_own','invite.manage','referral.view_own','referral.manage')",
        ));
        $starter = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_referral_campaigns WHERE campaign_key='community'",
        ));

        return $tables === 5 && $rules === 25 && $starter === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Referral schema, starter campaign or permission defaults are incomplete.');
    }
}
