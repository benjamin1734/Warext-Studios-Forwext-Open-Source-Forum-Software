<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

interface AiModerationMetricsStore
{
    public function record(AiModerationMetric $metric): void;
}
