<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final readonly class AiModerationCostPolicy
{
    public function __construct(
        public int $inputMicrosPerMillionTokens = 0,
        public int $outputMicrosPerMillionTokens = 0,
    ) {
        if ($this->inputMicrosPerMillionTokens < 0 || $this->outputMicrosPerMillionTokens < 0) {
            throw new InvalidArgumentException('AI moderation pricing cannot be negative.');
        }
    }

    public function price(AiModerationUsage $usage): AiModerationUsage
    {
        $numerator = ($usage->inputTokens * $this->inputMicrosPerMillionTokens)
            + ($usage->outputTokens * $this->outputMicrosPerMillionTokens);
        $cost = $numerator === 0 ? 0 : intdiv($numerator + 999_999, 1_000_000);
        return $usage->withCostMicros($cost);
    }
}
