<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final readonly class AiModerationPolicy
{
    public function __construct(
        private float $flagThreshold = 0.25,
        private float $queueThreshold = 0.50,
        private float $rejectThreshold = 0.85,
    ) {
        foreach ([$this->flagThreshold, $this->queueThreshold, $this->rejectThreshold] as $threshold) {
            if (!is_finite($threshold) || $threshold < 0.0 || $threshold > 1.0) {
                throw new InvalidArgumentException('AI moderation thresholds must be between 0 and 1.');
            }
        }
        if (!($this->flagThreshold <= $this->queueThreshold
            && $this->queueThreshold <= $this->rejectThreshold)
        ) {
            throw new InvalidArgumentException('AI moderation thresholds must be ordered flag <= queue <= reject.');
        }
    }

    public function decide(
        AiModerationAssessment $assessment,
        ?AiModerationHumanOverride $override = null,
    ): AiModerationDecision {
        if ($override !== null) {
            return new AiModerationDecision(
                $override->action,
                $assessment,
                true,
                $override->reason,
            );
        }

        if ($assessment->fallbackReason !== null) {
            return new AiModerationDecision(AiModerationAction::Queue, $assessment);
        }

        $action = match (true) {
            $assessment->riskScore >= $this->rejectThreshold => AiModerationAction::Reject,
            $assessment->riskScore >= $this->queueThreshold => AiModerationAction::Queue,
            $assessment->riskScore >= $this->flagThreshold => AiModerationAction::Flag,
            default => AiModerationAction::Allow,
        };
        return new AiModerationDecision($action, $assessment);
    }
}
