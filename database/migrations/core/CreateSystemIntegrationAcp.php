<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSystemIntegrationAcp implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260925070000_system_integration_acp');
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
            "INSERT INTO forwext_permissions "
            . "(permission_key,value_type,description,created_at_utc,updated_at_utc) VALUES "
            . "('integration.manage','flag','Manage system and integration configuration.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . "ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)"
        ));

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permission_template_rules '
                . '(template_key,permission_key,effect,numeric_limit) '
                . 'VALUES (:template_key,:permission_key,:effect,NULL) '
                . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                [
                    'template_key'=>$templateKey,
                    'permission_key'=>'integration.manage',
                    'effect'=>$templateKey === 'administrator' ? 'allow' : 'deny',
                ],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $permission = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key='integration.manage'"
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE permission_key='integration.manage' "
            . "AND template_key IN ('new_user','member','verified','moderator','administrator')"
        ));
        $administrator = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE permission_key='integration.manage' AND template_key='administrator' AND effect='allow'"
        ));

        return (int) $permission === 1 && (int) $rules === 5 && (int) $administrator === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('System integration ACP permission policy is incomplete.');
    }
}
