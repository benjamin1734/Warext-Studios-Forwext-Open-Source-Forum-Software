<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAiModerationOverrides implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918030000_ai_moderation_overrides');
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
            'CREATE TABLE IF NOT EXISTS forwext_ai_moderation_overrides ('
            . 'content_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'action VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'reason VARCHAR(255) NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'expires_at_utc DATETIME(6) NULL,'
            . 'PRIMARY KEY (content_fingerprint),'
            . 'KEY idx_forwext_ai_override_expiry (expires_at_utc,action,content_fingerprint),'
            . 'KEY idx_forwext_ai_override_actor (actor_user_id,created_at_utc),'
            . 'CONSTRAINT fk_forwext_ai_override_actor FOREIGN KEY (actor_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_permissions '
            . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
            . "VALUES ('ai.moderation.override','flag','Override AI moderation decisions for exact content fingerprints.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
            . 'updated_at_utc=VALUES(updated_at_utc)',
        ));

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_template_rules '
                . '(template_key,permission_key,effect,numeric_limit) '
                . "VALUES (:template_key,'ai.moderation.override',:effect,NULL) "
                . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                [
                    'template_key'=>$templateKey,
                    'effect'=>in_array($templateKey,['moderator','administrator'],true) ? 'allow' : 'deny',
                ],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_ai_moderation_overrides'",
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS '
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_ai_moderation_overrides' "
            . "AND INDEX_NAME IN ('idx_forwext_ai_override_expiry','idx_forwext_ai_override_actor')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . "WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_forwext_ai_override_actor'",
        ));
        $permission = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key='ai.moderation.override' AND value_type='flag'",
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key='ai.moderation.override'",
        ));

        return (int) $table === 1
            && (int) $indexes === 2
            && (int) $foreignKeys === 1
            && (int) $permission === 1
            && (int) $rules === 5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('AI moderation override schema or permission defaults are incomplete.');
    }
}
