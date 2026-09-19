<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use RuntimeException;

final readonly class DatabaseAiModerationMetricsStore implements AiModerationMetricsStore
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function record(AiModerationMetric $metric): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_ai_moderation_metrics '
            . '(metric_id,content_fingerprint,forum_node_id,provider_key,model,prompt_version,redacted,'
            . 'input_tokens,output_tokens,cost_micros,created_at_utc) '
            . 'VALUES (:metric_id,:content_fingerprint,:forum_node_id,:provider_key,:model,:prompt_version,:redacted,'
            . ':input_tokens,:output_tokens,:cost_micros,:created_at_utc)',
            [
                'metric_id'=>$metric->metricId->value(),
                'content_fingerprint'=>$metric->contentFingerprint,
                'forum_node_id'=>$metric->forumNodeId?->value(),
                'provider_key'=>$metric->providerKey,
                'model'=>$metric->model,
                'prompt_version'=>$metric->promptVersion,
                'redacted'=>$metric->redacted,
                'input_tokens'=>$metric->usage->inputTokens,
                'output_tokens'=>$metric->usage->outputTokens,
                'cost_micros'=>$metric->usage->costMicros,
                'created_at_utc'=>$metric->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ],
        ));
        if ($affected !== 1) {
            throw new RuntimeException('AI moderation usage metric was not persisted.');
        }
    }
}
