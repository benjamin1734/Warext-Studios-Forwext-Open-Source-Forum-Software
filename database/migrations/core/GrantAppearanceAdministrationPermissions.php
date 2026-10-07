<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class GrantAppearanceAdministrationPermissions implements Migration
{
    /** @var array<string,string> */
    private const PERMISSIONS = [
        'appearance.manage' => 'Manage site appearance and theme configuration.',
        'appearance.advanced' => 'Use advanced appearance customization surfaces.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20261007181000_appearance_administration_permissions');
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
        return true;
    }

    public function up(MigrationContext $context): void
    {
        foreach (self::PERMISSIONS as $permissionKey => $description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . 'VALUES (:permission_key,\'flag\',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$permissionKey,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            foreach (array_keys(self::PERMISSIONS) as $permissionKey) {
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules '
                    . '(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template_key,:permission_key,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    [
                        'template_key'=>$templateKey,
                        'permission_key'=>$permissionKey,
                        'effect'=>$templateKey === 'administrator' ? 'allow' : 'deny',
                    ],
                ));
            }
        }

        foreach (array_keys(self::PERMISSIONS) as $permissionKey) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_global_rules '
                . '(subject_type,subject_id,permission_key,effect,numeric_limit,updated_at_utc) '
                . "SELECT 'role',role_id,:permission_key,'allow',NULL,UTC_TIMESTAMP(6) "
                . "FROM forwext_roles WHERE role_key='administrator' AND is_protected=1 "
                . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL,'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$permissionKey],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $definitions = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions "
            . "WHERE permission_key IN ('appearance.manage','appearance.advanced') AND value_type='flag'",
        ));
        $templateRules = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE permission_key IN ('appearance.manage','appearance.advanced') "
            . "AND template_key IN ('new_user','member','verified','moderator','administrator')",
        ));
        $administratorTemplateAllows = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE permission_key IN ('appearance.manage','appearance.advanced') "
            . "AND template_key='administrator' AND effect='allow'",
        ));
        $administratorRoles = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_roles WHERE role_key='administrator' AND is_protected=1",
        ));
        $administratorRoleAllows = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_global_rules p "
            . "INNER JOIN forwext_roles r ON r.role_id=p.subject_id "
            . "WHERE p.subject_type='role' AND r.role_key='administrator' AND r.is_protected=1 "
            . "AND p.permission_key IN ('appearance.manage','appearance.advanced') AND p.effect='allow'",
        ));

        return $definitions === 2
            && $templateRules === 10
            && $administratorTemplateAllows === 2
            && ($administratorRoles === 0 || $administratorRoleAllows === 2)
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Appearance administration permission policy is incomplete.');
    }
}
