<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

final readonly class NullAiModerationDecisionStore implements AiModerationDecisionStore
{
    public function record(AiModerationDecisionRecord $record): void
    {
    }
}
