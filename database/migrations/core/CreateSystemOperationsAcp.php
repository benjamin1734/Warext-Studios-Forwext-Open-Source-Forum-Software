<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Admin\Operations\SystemOperationsService;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSystemOperationsAcp implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260925090000_system_operations_acp');
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
        $descriptions = [
            SystemOperationsService::HEALTH_PERMISSION => 'View system health, runtime capabilities and integrity diagnostics.',
            SystemOperationsService::LOG_PERMISSION => 'View bounded and redacted structured application logs.',
            SystemOperationsService::JOB_PERMISSION => 'Manage failed jobs and manually enqueue registered scheduled tasks.',
            SystemOperationsService::BACKUP_PERMISSION => 'Create, verify and delete protected logical database backups.',
            SystemOperationsService::MAINTENANCE_PERMISSION => 'Enable or disable site maintenance mode.',
            SystemOperationsService::REPAIR_PERMISSION => 'Run bounded system repair and cleanup tools.',
        ];

        foreach ($descriptions as $permission => $description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . 'VALUES (:permission_key,\'flag\',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$permission,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            foreach (SystemOperationsService::permissions() as $permission) {
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules '
                    . '(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template_key,:permission_key,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    [
                        'template_key'=>$templateKey,
                        'permission_key'=>$permission,
                        'effect'=>$templateKey === 'administrator' ? 'allow' : 'deny',
                    ],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $permissions = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key LIKE 'system.%'"
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE permission_key LIKE 'system.%' "
            . "AND template_key IN ('new_user','member','verified','moderator','administrator')"
        ));
        $administrator = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE permission_key LIKE 'system.%' AND template_key='administrator' AND effect='allow'"
        ));

        return (int) $permissions >= 6 && (int) $rules >= 30 && (int) $administrator >= 6
            ? MigrationVerification::passed()
            : MigrationVerification::failed('System operations ACP permission policy is incomplete.');
    }
}
