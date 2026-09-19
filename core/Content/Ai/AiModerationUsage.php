<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final readonly class AiModerationUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $costMicros = 0,
    ) {
        if ($this->inputTokens < 0 || $this->outputTokens < 0 || $this->costMicros < 0) {
            throw new InvalidArgumentException('AI moderation usage values cannot be negative.');
        }
        if ($this->inputTokens > 100_000_000 || $this->outputTokens > 100_000_000) {
            throw new InvalidArgumentException('AI moderation token usage exceeds the safety limit.');
        }
    }

    public function withCostMicros(int $costMicros): self
    {
        return new self($this->inputTokens, $this->outputTokens, $costMicros);
    }
}
