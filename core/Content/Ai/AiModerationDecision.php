<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

final readonly class AiModerationDecision
{
    public function __construct(
        public AiModerationAction $action,
        public AiModerationAssessment $assessment,
        public bool $humanOverride = false,
        public ?string $overrideReason = null,
    ) {
    }
}
