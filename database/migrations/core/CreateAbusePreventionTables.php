<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAbusePreventionTables implements Migration
{
    private const PERMISSIONS = [
        'moderation.abuse.view' => 'View anti-spam and abuse events.',
        'moderation.abuse.manage_rules' => 'Manage automated anti-abuse rules.',
        'moderation.abuse.cleanup' => 'Run abuse cleanup through normal content moderation permissions.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918004000_abuse_prevention');
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
            "CREATE TABLE IF NOT EXISTS forwext_abuse_rules ("
            . "rule_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "label VARCHAR(120) NOT NULL,"
            . "event_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "signal_key VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "hit_limit INT UNSIGNED NOT NULL,"
            . "window_seconds INT UNSIGNED NOT NULL,"
            . "action VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,"
            . "priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,"
            . "created_at_utc DATETIME(6) NOT NULL,"
            . "updated_at_utc DATETIME(6) NOT NULL,"
            . "PRIMARY KEY (rule_key),"
            . "KEY idx_forwext_abuse_rule_lookup (event_type,active,priority,rule_key)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));

        $context->execute(new CompiledQuery(
            "CREATE TABLE IF NOT EXISTS forwext_abuse_counters ("
            . "rule_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "bucket_start_utc DATETIME(6) NOT NULL,"
            . "hits INT UNSIGNED NOT NULL DEFAULT 0,"
            . "PRIMARY KEY (rule_key,fingerprint,bucket_start_utc),"
            . "KEY idx_forwext_abuse_counter_bucket (bucket_start_utc),"
            . "CONSTRAINT fk_forwext_abuse_counter_rule FOREIGN KEY (rule_key) "
            . "REFERENCES forwext_abuse_rules (rule_key) ON DELETE CASCADE"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));

        $context->execute(new CompiledQuery(
            "CREATE TABLE IF NOT EXISTS forwext_abuse_events ("
            . "event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "event_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "decision VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "matched_rule_keys_json TEXT NOT NULL,"
            . "actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "target_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "target_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "identity_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "ip_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "device_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "content_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "occurred_at_utc DATETIME(6) NOT NULL,"
            . "resolved_at_utc DATETIME(6) NULL,"
            . "resolved_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "resolution VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "PRIMARY KEY (event_id),"
            . "KEY idx_forwext_abuse_event_queue (resolved_at_utc,occurred_at_utc,event_id),"
            . "KEY idx_forwext_abuse_event_user (actor_user_id,occurred_at_utc),"
            . "KEY idx_forwext_abuse_event_ip (ip_fingerprint,occurred_at_utc),"
            . "KEY idx_forwext_abuse_event_device (device_fingerprint,occurred_at_utc),"
            . "CONSTRAINT fk_forwext_abuse_event_actor FOREIGN KEY (actor_user_id) "
            . "REFERENCES forwext_users (user_id) ON DELETE SET NULL,"
            . "CONSTRAINT fk_forwext_abuse_event_resolver FOREIGN KEY (resolved_by_user_id) "
            . "REFERENCES forwext_users (user_id) ON DELETE SET NULL"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));

        foreach ([
            ['registration.ip.review','Kayıt IP yoğunluğu — inceleme','registration','ip',5,3600,'review',10],
            ['registration.ip.reject','Kayıt IP yoğunluğu — engelle','registration','ip',9,3600,'reject',20],
            ['registration.device.review','Kayıt cihaz yoğunluğu','registration','device',4,3600,'review',30],
            ['thread.user.review','Konu flood — inceleme','thread','user',3,60,'review',40],
            ['thread.user.reject','Konu flood — engelle','thread','user',8,60,'reject',50],
            ['thread.device.review','Konu cihaz yoğunluğu','thread','device',5,60,'review',60],
            ['thread.content.review','Tekrarlanan aynı konu başlığı','thread','content',2,86400,'review',65],
            ['post.user.review','Mesaj flood — inceleme','post','user',8,60,'review',70],
            ['post.user.reject','Mesaj flood — engelle','post','user',20,60,'reject',80],
            ['post.device.review','Mesaj cihaz yoğunluğu','post','device',12,60,'review',90],
            ['post.content.review','Tekrarlanan aynı mesaj','post','content',2,86400,'review',100],
        ] as [$key,$label,$eventType,$signal,$limit,$window,$action,$priority]) {
            $context->execute(new CompiledQuery(
                "INSERT INTO forwext_abuse_rules "
                . "(rule_key,label,event_type,signal_key,hit_limit,window_seconds,action,active,priority,created_at_utc,updated_at_utc) "
                . "VALUES (:rule_key,:label,:event_type,:signal_key,:hit_limit,:window_seconds,:action,1,:priority,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . "ON DUPLICATE KEY UPDATE rule_key=VALUES(rule_key)",
                [
                    'rule_key'=>$key,'label'=>$label,'event_type'=>$eventType,'signal_key'=>$signal,
                    'hit_limit'=>$limit,'window_seconds'=>$window,'action'=>$action,'priority'=>$priority,
                ],
            ));
        }

        foreach (self::PERMISSIONS as $key => $description) {
            $context->execute(new CompiledQuery(
                "INSERT INTO forwext_permissions "
                . "(permission_key,value_type,description,created_at_utc,updated_at_utc) "
                . "VALUES (:permission_key,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . "ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),"
                . "updated_at_utc=VALUES(updated_at_utc)",
                ['permission_key'=>$key,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            foreach (array_keys(self::PERMISSIONS) as $permissionKey) {
                $effect = in_array($templateKey, ['moderator','administrator'], true) ? 'allow' : 'deny';
                $context->execute(new CompiledQuery(
                    "INSERT INTO forwext_permission_template_rules "
                    . "(template_key,permission_key,effect,numeric_limit) "
                    . "VALUES (:template_key,:permission_key,:effect,NULL) "
                    . "ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL",
                    ['template_key'=>$templateKey,'permission_key'=>$permissionKey,'effect'=>$effect],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME IN ('forwext_abuse_rules','forwext_abuse_counters','forwext_abuse_events')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA=DATABASE() "
            . "AND CONSTRAINT_NAME IN ('fk_forwext_abuse_counter_rule','fk_forwext_abuse_event_actor','fk_forwext_abuse_event_resolver')",
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_abuse_rules WHERE rule_key IN ("
            . "'registration.ip.review','registration.ip.reject','registration.device.review',"
            . "'thread.user.review','thread.user.reject','thread.device.review','thread.content.review',"
            . "'post.user.review','post.user.reject','post.device.review','post.content.review')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN ("
            . "'moderation.abuse.view','moderation.abuse.manage_rules','moderation.abuse.cleanup')",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('moderation.abuse.view','moderation.abuse.manage_rules','moderation.abuse.cleanup')",
        ));

        return (int) $tables === 3
            && (int) $foreignKeys === 3
            && (int) $rules === 11
            && (int) $permissions === 3
            && (int) $templateRules === 15
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Anti-abuse schema, default rules or permission defaults are incomplete.');
    }
}
