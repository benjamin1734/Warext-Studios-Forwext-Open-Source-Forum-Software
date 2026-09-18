<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateDisciplineTables implements Migration
{
    private const PERMISSIONS = [
        'moderation.discipline.view' => 'View warning, restriction, suspension and ban records.',
        'moderation.warning.issue' => 'Issue configured warning definitions to users.',
        'moderation.warning.manage' => 'Manage warning definitions and point/expiry defaults.',
        'moderation.restriction.manage' => 'Apply posting and content restrictions.',
        'moderation.ban.manage' => 'Apply temporary suspensions and temporary/permanent bans.',
        'moderation.discipline.revoke' => 'Revoke active discipline actions.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918003000_discipline_system');
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
            "CREATE TABLE IF NOT EXISTS forwext_warning_definitions ("
            . "definition_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "label VARCHAR(100) NOT NULL,"
            . "description VARCHAR(500) NOT NULL DEFAULT '',"
            . "points SMALLINT UNSIGNED NOT NULL DEFAULT 0,"
            . "expiry_days SMALLINT UNSIGNED NULL,"
            . "active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,"
            . "sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,"
            . "created_at_utc DATETIME(6) NOT NULL,"
            . "updated_at_utc DATETIME(6) NOT NULL,"
            . "PRIMARY KEY (definition_key),"
            . "KEY idx_forwext_warning_definition_active (active,sort_order,definition_key)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));

        foreach ([
            ['minor', 'Hafif ihlal', 'Düşük etkili ilk seviye uyarı.', 1, 30, 10],
            ['standard', 'Standart ihlal', 'Tekrarlanan veya orta düzey kural ihlali.', 3, 90, 20],
            ['serious', 'Ciddi ihlal', 'Ciddi kural veya topluluk güvenliği ihlali.', 5, 180, 30],
            ['severe', 'Ağır ihlal', 'Ağır veya tekrarlanan ihlal.', 10, 365, 40],
        ] as [$key, $label, $description, $points, $expiryDays, $sortOrder]) {
            $context->execute(new CompiledQuery(
                "INSERT INTO forwext_warning_definitions "
                . "(definition_key,label,description,points,expiry_days,active,sort_order,created_at_utc,updated_at_utc) "
                . "VALUES (:definition_key,:label,:description,:points,:expiry_days,1,:sort_order,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . "ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),points=VALUES(points),"
                . "expiry_days=VALUES(expiry_days),sort_order=VALUES(sort_order),updated_at_utc=VALUES(updated_at_utc)",
                [
                    'definition_key' => $key,
                    'label' => $label,
                    'description' => $description,
                    'points' => $points,
                    'expiry_days' => $expiryDays,
                    'sort_order' => $sortOrder,
                ],
            ));
        }

        $context->execute(new CompiledQuery(
            "CREATE TABLE IF NOT EXISTS forwext_discipline_actions ("
            . "action_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "action_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "warning_definition_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "reason_text VARCHAR(2000) NOT NULL,"
            . "points SMALLINT UNSIGNED NOT NULL DEFAULT 0,"
            . "appealable TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,"
            . "starts_at_utc DATETIME(6) NOT NULL,"
            . "expires_at_utc DATETIME(6) NULL,"
            . "revoked_at_utc DATETIME(6) NULL,"
            . "revoked_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "revoke_reason VARCHAR(1000) NULL,"
            . "created_at_utc DATETIME(6) NOT NULL,"
            . "PRIMARY KEY (action_id),"
            . "KEY idx_forwext_discipline_user_active (user_id,action_type,revoked_at_utc,expires_at_utc,starts_at_utc),"
            . "KEY idx_forwext_discipline_queue (action_type,starts_at_utc,action_id),"
            . "CONSTRAINT fk_forwext_discipline_user FOREIGN KEY (user_id) REFERENCES forwext_users (user_id) ON DELETE CASCADE,"
            . "CONSTRAINT fk_forwext_discipline_actor FOREIGN KEY (actor_user_id) REFERENCES forwext_users (user_id) ON DELETE SET NULL,"
            . "CONSTRAINT fk_forwext_discipline_warning FOREIGN KEY (warning_definition_key) "
            . "REFERENCES forwext_warning_definitions (definition_key) ON DELETE SET NULL,"
            . "CONSTRAINT fk_forwext_discipline_revoker FOREIGN KEY (revoked_by_user_id) "
            . "REFERENCES forwext_users (user_id) ON DELETE SET NULL"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));

        $context->execute(new CompiledQuery(
            "CREATE TABLE IF NOT EXISTS forwext_discipline_action_restrictions ("
            . "action_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "restriction_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "PRIMARY KEY (action_id,restriction_key),"
            . "KEY idx_forwext_discipline_restriction_key (restriction_key,action_id),"
            . "CONSTRAINT fk_forwext_discipline_restriction_action FOREIGN KEY (action_id) "
            . "REFERENCES forwext_discipline_actions (action_id) ON DELETE CASCADE"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));

        foreach (self::PERMISSIONS as $key => $description) {
            $context->execute(new CompiledQuery(
                "INSERT INTO forwext_permissions "
                . "(permission_key,value_type,description,created_at_utc,updated_at_utc) "
                . "VALUES (:permission_key,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . "ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),"
                . "updated_at_utc=VALUES(updated_at_utc)",
                ['permission_key' => $key, 'description' => $description],
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
                    [
                        'template_key' => $templateKey,
                        'permission_key' => $permissionKey,
                        'effect' => $effect,
                    ],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME IN ('forwext_warning_definitions','forwext_discipline_actions','forwext_discipline_action_restrictions')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA=DATABASE() "
            . "AND CONSTRAINT_NAME IN ('fk_forwext_discipline_user','fk_forwext_discipline_actor',"
            . "'fk_forwext_discipline_warning','fk_forwext_discipline_revoker','fk_forwext_discipline_restriction_action')",
        ));
        $definitions = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_warning_definitions "
            . "WHERE definition_key IN ('minor','standard','serious','severe')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN ("
            . "'moderation.discipline.view','moderation.warning.issue','moderation.warning.manage',"
            . "'moderation.restriction.manage','moderation.ban.manage','moderation.discipline.revoke')",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('moderation.discipline.view','moderation.warning.issue','moderation.warning.manage',"
            . "'moderation.restriction.manage','moderation.ban.manage','moderation.discipline.revoke')",
        ));

        return (int) $tables === 3
            && (int) $foreignKeys === 5
            && (int) $definitions === 4
            && (int) $permissions === 6
            && (int) $templateRules === 30
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Discipline schema, warning definitions or permission defaults are incomplete.');
    }
}
