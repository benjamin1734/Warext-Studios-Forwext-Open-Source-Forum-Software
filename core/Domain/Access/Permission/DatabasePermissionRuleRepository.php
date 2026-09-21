<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Entity\EntityId;
use Throwable;
use UnexpectedValueException;

final readonly class DatabasePermissionRuleRepository implements PermissionRuleRepository
{
    /** @var array<string,list<string>> */
    private const DISCIPLINE_RESTRICTIONS = [
        'forum.thread.create' => ['posting', 'content'],
        'forum.post.create' => ['posting', 'content'],
        'profile.post.create' => ['posting', 'content'],
        'profile.post.comment' => ['posting', 'content'],
        'portfolio.create' => ['content'],
        'portfolio.manage_own' => ['content'],
        'marketplace.listing.create' => ['content'],
        'marketplace.listing.manage_own' => ['content'],
        'giveaway.create' => ['content'],
    ];

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

        $disciplineRestrictions = self::DISCIPLINE_RESTRICTIONS[$key->value()] ?? [];
        if ($disciplineRestrictions !== [] && $this->hasActiveDisciplineRestriction(
            $assignment->userId(),
            $disciplineRestrictions,
        )) {
            $rows[] = [
                'subject_type' => PermissionSubjectType::User->value,
                'subject_id' => $assignment->userId()->value(),
                'effect' => PermissionEffect::Deny->value,
                'numeric_limit' => null,
                'node_id' => $nodeId?->value(),
            ];
        }

        foreach ($this->subscriptionPermissionRows($key, $assignment->userId()) as $subscriptionRow) {
            $rows[] = $subscriptionRow;
        }

        return array_map($this->hydrateRule(...), $rows);
    }

    /** @return list<array{subject_type:string,subject_id:string,effect:string,numeric_limit:null,node_id:null}> */
    private function subscriptionPermissionRows(PermissionKey $key, EntityId $userId): array
    {
        try {
            $rows = $this->database->fetchAll(new CompiledQuery(
                'SELECT DISTINCT sp.permission_key FROM forwext_user_subscriptions s '
                . 'INNER JOIN forwext_subscription_plan_permissions sp ON sp.plan_id=s.plan_id '
                . "WHERE s.user_id=:user_id AND s.state='active' "
                . 'AND (s.ends_at_utc IS NULL OR s.ends_at_utc>UTC_TIMESTAMP(6)) '
                . 'AND sp.permission_key=:permission_key',
                ['user_id'=>$userId->value(),'permission_key'=>$key->value()],
            ));
        } catch (Throwable) {
            return [];
        }
        if ($rows === []) return [];
        return [[
            'subject_type'=>PermissionSubjectType::User->value,
            'subject_id'=>$userId->value(),
            'effect'=>PermissionEffect::Allow->value,
            'numeric_limit'=>null,
            'node_id'=>null,
        ]];
    }

    /** @param list<string> $restrictions */
    private function hasActiveDisciplineRestriction(EntityId $userId, array $restrictions): bool
    {
        $parameters = ['user_id' => $userId->value()];
        $placeholders = [];
        foreach ($restrictions as $index => $restriction) {
            $name = 'restriction_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $restriction;
        }

        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_discipline_actions` a '
            . 'INNER JOIN `forwext_discipline_action_restrictions` r ON r.`action_id` = a.`action_id` '
            . 'WHERE a.`user_id` = :user_id AND a.`action_type` = \'restriction\' '
            . 'AND r.`restriction_key` IN (' . implode(', ', $placeholders) . ') '
            . 'AND a.`revoked_at_utc` IS NULL '
            . 'AND a.`starts_at_utc` <= UTC_TIMESTAMP(6) '
            . 'AND (a.`expires_at_utc` IS NULL OR a.`expires_at_utc` > UTC_TIMESTAMP(6))',
            $parameters,
        )) > 0;
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
