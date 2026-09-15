<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Template;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class DatabasePermissionTemplateRuleWriter implements PermissionTemplateRuleWriter
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function apply(
        PermissionTemplate $template,
        PermissionSubjectType $subjectType,
        EntityId $subjectId,
    ): int {
        return $this->database->transaction(function (TransactionalQueryExecutor $database) use ($template, $subjectType, $subjectId): int {
            foreach ($template->rules() as $rule) {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_permission_global_rules` '
                    . '(`subject_type`, `subject_id`, `permission_key`, `effect`, `numeric_limit`, `updated_at_utc`) '
                    . 'VALUES (:subject_type, :subject_id, :permission_key, :effect, :numeric_limit, UTC_TIMESTAMP(6)) '
                    . 'ON DUPLICATE KEY UPDATE `effect` = VALUES(`effect`), '
                    . '`numeric_limit` = VALUES(`numeric_limit`), `updated_at_utc` = VALUES(`updated_at_utc`)',
                    [
                        'subject_type' => $subjectType->value,
                        'subject_id' => $subjectId->value(),
                        'permission_key' => $rule->definition()->key()->value(),
                        'effect' => $rule->effect()->value,
                        'numeric_limit' => $rule->numericLimit(),
                    ],
                ));
            }

            return count($template->rules());
        });
    }
}
