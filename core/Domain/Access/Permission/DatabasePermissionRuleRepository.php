<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Entity\EntityId;
use UnexpectedValueException;

final readonly class DatabasePermissionRuleRepository implements PermissionRuleRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `permission_key`, `value_type` FROM `forwext_permissions` WHERE `permission_key` = :permission_key LIMIT 1',
            ['permission_key' => $key->value()],
        ));

        if ($row === null) {
            return null;
        }

        return new PermissionDefinition(
            PermissionKey::fromString((string) $row['permission_key']),
            PermissionValueType::from((string) $row['value_type']),
        );
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        [$subjectSql, $parameters] = $this->subjectFilter($assignment);
        $parameters['permission_key'] = $key->value();

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `subject_type`, `subject_id`, `effect`, `numeric_limit`, NULL AS `node_id` '
            . 'FROM `forwext_permission_global_rules` '
            . 'WHERE `permission_key` = :permission_key AND (' . $subjectSql . ')',
            $parameters,
        ));

        if ($nodeId !== null) {
            $nodeParameters = $parameters;
            $nodeParameters['node_id'] = $nodeId->value();
            $rows = array_merge($rows, $this->database->fetchAll(new CompiledQuery(
                'SELECT `subject_type`, `subject_id`, `effect`, `numeric_limit`, `node_id` '
                . 'FROM `forwext_permission_node_rules` '
                . 'WHERE `permission_key` = :permission_key AND `node_id` = :node_id AND (' . $subjectSql . ')',
                $nodeParameters,
            )));
        }

        return array_map($this->hydrateRule(...), $rows);
    }

    /** @return array{0: string, 1: array<string, string|int|float|bool|null>} */
    private function subjectFilter(UserAccessAssignment $assignment): array
    {
        $parameters = ['user_id' => $assignment->userId()->value()];
        $clauses = ['(`subject_type` = \'user\' AND `subject_id` = :user_id)'];

        $groupPlaceholders = [];
        $groupIds = [$assignment->primaryGroupId(), ...$assignment->secondaryGroupIds()];
        foreach ($groupIds as $index => $groupId) {
            $name = 'group_' . $index;
            $groupPlaceholders[] = ':' . $name;
            $parameters[$name] = $groupId->value();
        }
        $clauses[] = '(`subject_type` = \'group\' AND `subject_id` IN (' . implode(', ', $groupPlaceholders) . '))';

        $rolePlaceholders = [];
        foreach ($assignment->roleIds() as $index => $roleId) {
            $name = 'role_' . $index;
            $rolePlaceholders[] = ':' . $name;
            $parameters[$name] = $roleId->value();
        }
        if ($rolePlaceholders !== []) {
            $clauses[] = '(`subject_type` = \'role\' AND `subject_id` IN (' . implode(', ', $rolePlaceholders) . '))';
        }

        return [implode(' OR ', $clauses), $parameters];
    }

    /** @param array<string, mixed> $row */
    private function hydrateRule(array $row): PermissionRule
    {
        foreach (['subject_type', 'subject_id', 'effect'] as $required) {
            if (!array_key_exists($required, $row)) {
                throw new UnexpectedValueException('Permission rule row is missing required data.');
            }
        }

        $limit = $row['numeric_limit'] ?? null;
        if ($limit !== null && !is_int($limit) && !(is_string($limit) && preg_match('/^[0-9]+$/D', $limit) === 1)) {
            throw new UnexpectedValueException('Permission numeric limit contains invalid data.');
        }

        $node = $row['node_id'] ?? null;

        return new PermissionRule(
            PermissionSubjectType::from((string) $row['subject_type']),
            EntityId::fromString((string) $row['subject_id']),
            PermissionEffect::from((string) $row['effect']),
            $node === null ? null : EntityId::fromString((string) $node),
            $limit === null ? null : (int) $limit,
        );
    }
}
