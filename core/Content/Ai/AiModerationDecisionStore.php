<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

interface AiModerationDecisionStore
{
    public function record(AiModerationDecisionRecord $record): void;
}
