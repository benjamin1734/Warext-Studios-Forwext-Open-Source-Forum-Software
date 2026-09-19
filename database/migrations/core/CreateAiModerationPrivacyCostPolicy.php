<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAiModerationPrivacyCostPolicy implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919070000_ai_moderation_privacy_cost_policy');
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
            'CREATE TABLE IF NOT EXISTS forwext_ai_moderation_forum_policies ('
            . 'node_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'prompt_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'core.v1\','
            . 'redact_sensitive_data TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'flag_threshold DECIMAL(7,6) UNSIGNED NOT NULL DEFAULT 0.250000,'
            . 'queue_threshold DECIMAL(7,6) UNSIGNED NOT NULL DEFAULT 0.500000,'
            . 'reject_threshold DECIMAL(7,6) UNSIGNED NOT NULL DEFAULT 0.850000,'
            . 'input_cost_micros_per_million BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'output_cost_micros_per_million BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (node_id),'
            . 'KEY idx_forwext_ai_forum_policy_provider (provider_key,enabled,node_id),'
            . 'CONSTRAINT fk_forwext_ai_forum_policy_node FOREIGN KEY (node_id) '
            . 'REFERENCES forwext_nodes (node_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_ai_moderation_metrics ('
            . 'metric_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'content_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'forum_node_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'provider_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'model VARCHAR(191) NOT NULL,'
            . 'prompt_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'redacted TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . 'input_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'output_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'cost_micros BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (metric_id),'
            . 'KEY idx_forwext_ai_metrics_forum_time (forum_node_id,created_at_utc,metric_id),'
            . 'KEY idx_forwext_ai_metrics_provider_time (provider_key,model,created_at_utc),'
            . 'KEY idx_forwext_ai_metrics_fingerprint (content_fingerprint,created_at_utc),'
            . 'CONSTRAINT fk_forwext_ai_metrics_forum FOREIGN KEY (forum_node_id) '
            . 'REFERENCES forwext_nodes (node_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_ai_moderation_feedback ('
            . 'feedback_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'decision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'note VARCHAR(1000) NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (feedback_id),'
            . 'KEY idx_forwext_ai_feedback_decision (decision_id,created_at_utc,feedback_id),'
            . 'KEY idx_forwext_ai_feedback_kind (kind,created_at_utc,feedback_id),'
            . 'CONSTRAINT fk_forwext_ai_feedback_decision FOREIGN KEY (decision_id) '
            . 'REFERENCES forwext_ai_moderation_decisions (decision_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_ai_feedback_actor FOREIGN KEY (actor_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_ai_moderation_forum_policies','forwext_ai_moderation_metrics','forwext_ai_moderation_feedback')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() '
            . "AND CONSTRAINT_NAME IN ('fk_forwext_ai_forum_policy_node','fk_forwext_ai_metrics_forum',"
            . "'fk_forwext_ai_feedback_decision','fk_forwext_ai_feedback_actor')",
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,\':\',INDEX_NAME)) FROM information_schema.STATISTICS '
            . "WHERE TABLE_SCHEMA=DATABASE() AND ("
            . "(TABLE_NAME='forwext_ai_moderation_forum_policies' AND INDEX_NAME='idx_forwext_ai_forum_policy_provider') OR "
            . "(TABLE_NAME='forwext_ai_moderation_metrics' AND INDEX_NAME IN ('idx_forwext_ai_metrics_forum_time','idx_forwext_ai_metrics_provider_time','idx_forwext_ai_metrics_fingerprint')) OR "
            . "(TABLE_NAME='forwext_ai_moderation_feedback' AND INDEX_NAME IN ('idx_forwext_ai_feedback_decision','idx_forwext_ai_feedback_kind')))",
        ));

        return (int) $tables === 3 && (int) $foreignKeys === 4 && (int) $indexes === 6
            ? MigrationVerification::passed()
            : MigrationVerification::failed('AI privacy, cost, feedback or per-forum policy schema is incomplete.');
    }
}
