<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Entity\EntityId;
use Throwable;

final readonly class PermissionEngine
{
    public function __construct(private PermissionRuleRepository $rules)
    {
    }

    public function resolve(
        PermissionKey $key,
        UserAccessAssignment $assignment,
        ?EntityId $nodeId = null,
    ): PermissionDecision {
        try {
            $definition = $this->rules->definition($key);
            if ($definition === null) {
                return PermissionDecision::deny('unknown_permission');
            }

            $rules = $this->rules->rules($key, $assignment, $nodeId);
        } catch (Throwable) {
            return PermissionDecision::deny('permission_repository_error');
        }

        $tiers = [];
        if ($nodeId !== null) {
            $tiers['node_user'] = $this->filterRules($rules, $assignment, $nodeId, true, true);
        }
        $tiers['global_user'] = $this->filterRules($rules, $assignment, null, true, true);
        if ($nodeId !== null) {
            $tiers['node_membership'] = $this->filterRules($rules, $assignment, $nodeId, false, false);
        }
        $tiers['global_membership'] = $this->filterRules($rules, $assignment, null, false, false);

        $trace = [];
        foreach ($tiers as $tier => $tierRules) {
            $decision = $this->resolveTier($definition, $tier, $tierRules, $trace);
            if ($decision !== null) {
                return $decision;
            }
        }

        return PermissionDecision::deny('implicit_deny', $trace);
    }

    /**
     * @param list<PermissionRule> $rules
     * @return list<PermissionRule>
     */
    private function filterRules(
        array $rules,
        UserAccessAssignment $assignment,
        ?EntityId $nodeId,
        bool $userOnly,
        bool $excludeMembership,
    ): array {
        $groups = [$assignment->primaryGroupId()->value() => true];
        foreach ($assignment->secondaryGroupIds() as $groupId) {
            $groups[$groupId->value()] = true;
        }

        $roles = [];
        foreach ($assignment->roleIds() as $roleId) {
            $roles[$roleId->value()] = true;
        }

        $filtered = array_filter(
            $rules,
            static function (PermissionRule $rule) use ($assignment, $nodeId, $userOnly, $excludeMembership, $groups, $roles): bool {
                $sameScope = $nodeId === null
                    ? $rule->nodeId() === null
                    : $rule->nodeId() !== null && $rule->nodeId()->equals($nodeId);

                if (!$sameScope) {
                    return false;
                }

                if ($userOnly) {
                    return $rule->subjectType() === PermissionSubjectType::User
                        && $rule->subjectId()->equals($assignment->userId());
                }

                if ($excludeMembership || $rule->subjectType() === PermissionSubjectType::User) {
                    return false;
                }

                return match ($rule->subjectType()) {
                    PermissionSubjectType::Group => isset($groups[$rule->subjectId()->value()]),
                    PermissionSubjectType::Role => isset($roles[$rule->subjectId()->value()]),
                    PermissionSubjectType::User => false,
                };
            },
        );

        usort(
            $filtered,
            static fn (PermissionRule $left, PermissionRule $right): int => [
                $left->subjectType()->value,
                $left->subjectId()->value(),
                $left->effect()->value,
                $left->numericLimit() ?? -1,
            ] <=> [
                $right->subjectType()->value,
                $right->subjectId()->value(),
                $right->effect()->value,
                $right->numericLimit() ?? -1,
            ],
        );

        return array_values($filtered);
    }

    /**
     * @param list<PermissionRule> $rules
     * @param list<PermissionTraceEntry> $trace
     */
    private function resolveTier(
        PermissionDefinition $definition,
        string $tier,
        array $rules,
        array &$trace,
    ): ?PermissionDecision {
        if ($rules === []) {
            return null;
        }

        $allowedLimits = [];
        $hasAllow = false;

        foreach ($rules as $rule) {
            if (!$rule->isValidFor($definition)) {
                $trace[] = new PermissionTraceEntry($tier, $rule, 'invalid_rule_denied');
                return PermissionDecision::deny('invalid_permission_rule', $trace);
            }

            if ($rule->effect() === PermissionEffect::Inherit) {
                $trace[] = new PermissionTraceEntry($tier, $rule, 'inherited');
                continue;
            }

            if ($rule->effect() === PermissionEffect::Deny) {
                $trace[] = new PermissionTraceEntry($tier, $rule, 'denied');
                return PermissionDecision::deny($tier . '_deny', $trace);
            }

            $hasAllow = true;
            $trace[] = new PermissionTraceEntry($tier, $rule, 'allowed');
            if ($definition->valueType() === PermissionValueType::Numeric) {
                $allowedLimits[] = $rule->numericLimit();
            }
        }

        if (!$hasAllow) {
            return null;
        }

        $limit = $definition->valueType() === PermissionValueType::Numeric
            ? min($allowedLimits)
            : null;

        return PermissionDecision::allow($limit, $tier . '_allow', $trace);
    }
}
