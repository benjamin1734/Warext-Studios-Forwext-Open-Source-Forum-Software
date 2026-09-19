<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class IntegrateContentGovernancePermissions implements Migration
{
    private const LEGACY_MAP = [
        'freshness.renew_own' => 'forum.thread.freshness.renew_own',
        'freshness.review' => 'forum.thread.freshness.review',
        'freshness.manage' => 'forum.thread.freshness.manage_policy',
    ];

    private const CANONICAL = [
        'forum.thread.freshness.renew_own' => 'Renew freshness for an authored thread in an authorized forum.',
        'forum.thread.freshness.renew_any' => 'Renew or reopen freshness for any thread in an authorized forum.',
        'forum.thread.freshness.review' => 'Review stale-thread freshness cases in an authorized forum.',
        'forum.thread.freshness.manage_policy' => 'Manage per-forum thread freshness policy.',
    ];

    public function id(): MigrationId { return MigrationId::fromString('20260919110000_content_governance_integration'); }
    public function owner(): MigrationOwner { return MigrationOwner::core(); }
    public function isIdempotent(): bool { return true; }
    public function isTransactional(): bool { return true; }

    public function up(MigrationContext $context): void
    {
        foreach (self::CANONICAL as $permissionKey => $description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . 'VALUES (:permission_key,\'flag\',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$permissionKey,'description'=>$description],
            ));
        }

        foreach (self::LEGACY_MAP as $legacy => $canonical) {
            $context->execute(new CompiledQuery(
                'INSERT IGNORE INTO forwext_permission_global_rules '
                . '(subject_type,subject_id,permission_key,effect,numeric_limit,updated_at_utc) '
                . 'SELECT subject_type,subject_id,:canonical,effect,numeric_limit,updated_at_utc '
                . 'FROM forwext_permission_global_rules WHERE permission_key=:legacy',
                ['canonical'=>$canonical,'legacy'=>$legacy],
            ));
            $context->execute(new CompiledQuery(
                'INSERT IGNORE INTO forwext_permission_node_rules '
                . '(node_id,subject_type,subject_id,permission_key,effect,numeric_limit,updated_at_utc) '
                . 'SELECT node_id,subject_type,subject_id,:canonical,effect,numeric_limit,updated_at_utc '
                . 'FROM forwext_permission_node_rules WHERE permission_key=:legacy',
                ['canonical'=>$canonical,'legacy'=>$legacy],
            ));
            $context->execute(new CompiledQuery(
                'INSERT IGNORE INTO forwext_permission_template_rules '
                . '(template_key,permission_key,effect,numeric_limit) '
                . 'SELECT template_key,:canonical,effect,numeric_limit '
                . 'FROM forwext_permission_template_rules WHERE permission_key=:legacy',
                ['canonical'=>$canonical,'legacy'=>$legacy],
            ));
            foreach ([
                'forwext_permission_global_rules',
                'forwext_permission_node_rules',
                'forwext_permission_template_rules',
            ] as $table) {
                $context->execute(new CompiledQuery(
                    'DELETE FROM ' . $table . ' WHERE permission_key=:legacy',
                    ['legacy'=>$legacy],
                ));
            }
            $context->execute(new CompiledQuery(
                'DELETE FROM forwext_permissions WHERE permission_key=:legacy',
                ['legacy'=>$legacy],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $canonical = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permissions WHERE value_type=\'flag\' '
            . "AND permission_key IN ('forum.thread.freshness.renew_own','forum.thread.freshness.renew_any',"
            . "'forum.thread.freshness.review','forum.thread.freshness.manage_policy')",
        ));
        $legacyDefinitions = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions "
            . "WHERE permission_key IN ('freshness.renew_own','freshness.review','freshness.manage')",
        ));
        $legacyRules = 0;
        foreach ([
            'forwext_permission_global_rules',
            'forwext_permission_node_rules',
            'forwext_permission_template_rules',
        ] as $table) {
            $legacyRules += (int) $context->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM ' . $table
                . " WHERE permission_key IN ('freshness.renew_own','freshness.review','freshness.manage')",
            ));
        }

        return (int) $canonical === 4 && (int) $legacyDefinitions === 0 && $legacyRules === 0
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Content-governance permission aliases were not normalized.');
    }
}
