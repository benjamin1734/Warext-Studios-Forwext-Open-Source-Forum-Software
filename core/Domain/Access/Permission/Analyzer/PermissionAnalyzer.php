<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Analyzer;

use Forwext\Core\Domain\Access\Permission\PermissionDecision;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionTraceEntry;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class PermissionAnalyzer
{
    public function __construct(private PermissionEngine $engine)
    {
    }

    public function analyze(
        PermissionKey $key,
        UserAccessAssignment $assignment,
        ?EntityId $nodeId = null,
    ): PermissionAnalysis {
        $decision = $this->engine->resolve($key, $assignment, $nodeId);
        $steps = array_map($this->step(...), $decision->trace());
        $decisiveTier = $this->decisiveTier($decision);

        return new PermissionAnalysis(
            $key,
            $nodeId,
            $decision->isAllowed(),
            $decision->numericLimit(),
            $decision->reason(),
            $this->summary($decision),
            $this->layers($decision, $steps, $nodeId !== null, $decisiveTier),
        );
    }

    private function step(PermissionTraceEntry $entry): PermissionAnalysisStep
    {
        $rule = $entry->rule();
        $subject = sprintf('%s %s', ucfirst($rule->subjectType()->value), $rule->subjectId()->value());
        $explanation = match ($entry->outcome()) {
            'inherited' => sprintf('%s inherits at this layer, so it does not decide the result.', $subject),
            'allowed' => $rule->numericLimit() === null
                ? sprintf('%s allows this permission at this layer.', $subject)
                : sprintf('%s allows this permission with numeric limit %d.', $subject, $rule->numericLimit()),
            'denied' => sprintf('%s denies this permission; deny wins inside the same layer.', $subject),
            'invalid_rule_denied' => sprintf(
                '%s has a malformed rule, so evaluation fails closed instead of guessing.',
                $subject,
            ),
            default => sprintf('%s produced outcome %s.', $subject, $entry->outcome()),
        };

        return new PermissionAnalysisStep(
            $entry->tier(),
            $rule->subjectType(),
            $rule->subjectId(),
            $rule->effect(),
            $rule->nodeId(),
            $rule->numericLimit(),
            $entry->outcome(),
            $explanation,
        );
    }

    /**
     * @param list<PermissionAnalysisStep> $steps
     * @return list<PermissionAnalysisLayer>
     */
    private function layers(
        PermissionDecision $decision,
        array $steps,
        bool $hasNode,
        ?string $decisiveTier,
    ): array {
        /** @var list<array{string, string, bool, string}> $specs */
        $specs = [
            ['node_user', 'Node user override', $hasNode, 'Checks a direct override for this user on the selected node.'],
            ['global_user', 'Global user override', true, 'Checks a direct override for this user across the site.'],
            ['node_membership', 'Node group and role rules', $hasNode, 'Combines matching group and role rules on the selected node.'],
            ['global_membership', 'Global group and role rules', true, 'Combines matching group and role rules across the site.'],
        ];

        $decisiveIndex = null;
        foreach ($specs as $index => $spec) {
            if ($spec[0] === $decisiveTier) {
                $decisiveIndex = $index;
                break;
            }
        }

        $layers = [];
        foreach ($specs as $index => [$key, $label, $enabled, $explanation]) {
            $layerSteps = array_values(array_filter(
                $steps,
                static fn (PermissionAnalysisStep $step): bool => $step->tier() === $key,
            ));

            if (!$enabled) {
                $state = PermissionAnalysisLayerState::NotApplicable;
            } elseif ($decisiveIndex !== null && $index > $decisiveIndex) {
                $state = PermissionAnalysisLayerState::NotReached;
            } elseif ($key === $decisiveTier) {
                $state = $decision->reason() === 'invalid_permission_rule'
                    ? PermissionAnalysisLayerState::FailClosed
                    : ($decision->isAllowed()
                        ? PermissionAnalysisLayerState::Allowed
                        : PermissionAnalysisLayerState::Denied);
            } elseif ($layerSteps === []) {
                $state = $this->isEarlyFailure($decision)
                    ? PermissionAnalysisLayerState::NotReached
                    : PermissionAnalysisLayerState::NoRule;
            } else {
                $state = PermissionAnalysisLayerState::Inherited;
            }

            $layers[] = new PermissionAnalysisLayer($key, $label, $state, $explanation, $layerSteps);
        }

        $fallbackState = match ($decision->reason()) {
            'implicit_deny' => PermissionAnalysisLayerState::Denied,
            'unknown_permission', 'permission_repository_error' => PermissionAnalysisLayerState::FailClosed,
            default => PermissionAnalysisLayerState::NotReached,
        };
        $fallbackExplanation = match ($decision->reason()) {
            'implicit_deny' => 'No higher layer resolved the permission, so the secure default is deny.',
            'unknown_permission' => 'The permission is not registered, so the analyzer cannot grant it.',
            'permission_repository_error' => 'Permission data could not be read safely, so the analyzer fails closed.',
            default => 'A higher-precedence layer already resolved the permission.',
        };
        $layers[] = new PermissionAnalysisLayer(
            'fallback',
            'Secure fallback',
            $fallbackState,
            $fallbackExplanation,
            [],
        );

        return $layers;
    }

    private function decisiveTier(PermissionDecision $decision): ?string
    {
        $tier = match ($decision->reason()) {
            'node_user_allow', 'node_user_deny' => 'node_user',
            'global_user_allow', 'global_user_deny' => 'global_user',
            'node_membership_allow', 'node_membership_deny' => 'node_membership',
            'global_membership_allow', 'global_membership_deny' => 'global_membership',
            default => null,
        };

        if ($tier !== null || $decision->reason() !== 'invalid_permission_rule') {
            return $tier;
        }

        $trace = $decision->trace();
        return $trace === [] ? null : $trace[count($trace) - 1]->tier();
    }

    private function summary(PermissionDecision $decision): string
    {
        $summary = match ($decision->reason()) {
            'node_user_allow' => 'Allowed by a direct user override on the selected node.',
            'global_user_allow' => 'Allowed by a direct user override that applies across the site.',
            'node_membership_allow' => 'Allowed by the user\'s group or role rules on the selected node.',
            'global_membership_allow' => 'Allowed by the user\'s global group or role rules.',
            'node_user_deny' => 'Denied by a direct user override on the selected node.',
            'global_user_deny' => 'Denied by a direct user override that applies across the site.',
            'node_membership_deny' => 'Denied by a group or role rule on the selected node.',
            'global_membership_deny' => 'Denied by a global group or role rule.',
            'invalid_permission_rule' => 'Denied safely because a malformed permission rule was encountered.',
            'unknown_permission' => 'Denied because this permission is not registered.',
            'permission_repository_error' => 'Denied safely because permission data could not be read.',
            'implicit_deny' => 'Denied because no applicable rule granted this permission.',
            default => $decision->isAllowed()
                ? 'Allowed by the permission engine.'
                : 'Denied by the permission engine.',
        };

        if ($decision->isAllowed() && $decision->numericLimit() !== null) {
            $summary .= sprintf(' Effective numeric limit: %d.', $decision->numericLimit());
        }

        return $summary;
    }

    private function isEarlyFailure(PermissionDecision $decision): bool
    {
        return in_array($decision->reason(), ['unknown_permission', 'permission_repository_error'], true);
    }
}
