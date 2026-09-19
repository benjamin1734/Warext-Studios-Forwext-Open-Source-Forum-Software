<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

final readonly class NullAiModerationMetricsStore implements AiModerationMetricsStore
{
    public function record(AiModerationMetric $metric): void
    {
    }
}
