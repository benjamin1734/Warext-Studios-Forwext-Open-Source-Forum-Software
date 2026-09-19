<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

interface AiModerationFeedbackStore
{
    public function record(AiModerationFeedback $feedback): void;
}
