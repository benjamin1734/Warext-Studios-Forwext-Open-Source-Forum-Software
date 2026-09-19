<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final class AiModerationPipelineAttributes
{
    public static function apply(
        ContentPipelineContext $context,
        AiModerationAssessment $assessment,
    ): ContentPipelineContext {
        return $context
            ->withAttribute('ai.provider', $assessment->providerKey)
            ->withAttribute('ai.model', $assessment->model)
            ->withAttribute('ai.risk_score', $assessment->riskScore)
            ->withAttribute('ai.fallback_reason', $assessment->fallbackReason)
            ->withAttribute('ai.prompt_version', $assessment->promptVersion)
            ->withAttribute('ai.redacted', $assessment->redacted)
            ->withAttribute('ai.input_tokens', $assessment->usage->inputTokens)
            ->withAttribute('ai.output_tokens', $assessment->usage->outputTokens)
            ->withAttribute('ai.cost_micros', $assessment->usage->costMicros);
    }

    public static function assessment(ContentPipelineContext $context): AiModerationAssessment
    {
        $provider = $context->attributes['ai.provider'] ?? null;
        $model = $context->attributes['ai.model'] ?? null;
        $risk = $context->attributes['ai.risk_score'] ?? null;
        $fallback = $context->attributes['ai.fallback_reason'] ?? null;
        $prompt = $context->attributes['ai.prompt_version'] ?? 'core.v1';
        $redacted = $context->attributes['ai.redacted'] ?? false;
        $input = $context->attributes['ai.input_tokens'] ?? 0;
        $output = $context->attributes['ai.output_tokens'] ?? 0;
        $cost = $context->attributes['ai.cost_micros'] ?? 0;
        if (!is_string($provider) || !is_string($model)
            || (!is_float($risk) && !is_int($risk))
            || ($fallback !== null && !is_string($fallback))
            || !is_string($prompt) || !is_bool($redacted)
            || !is_int($input) || !is_int($output) || !is_int($cost)
        ) {
            throw new InvalidArgumentException('AI moderation pipeline assessment attributes are invalid.');
        }
        return new AiModerationAssessment(
            $provider,
            $model,
            (float) $risk,
            [],
            $fallback,
            new AiModerationUsage($input, $output, $cost),
            $prompt,
            $redacted,
        );
    }

    public static function fingerprint(ContentPipelineContext $context): string
    {
        $stored = $context->attributes['ai.content_fingerprint'] ?? null;
        if (is_string($stored)) {
            AiModerationFingerprint::assert($stored);
            return $stored;
        }
        return AiModerationFingerprint::forContent($context->contentType, $context->text);
    }

    public static function forumNodeId(ContentPipelineContext $context): ?EntityId
    {
        $value = $context->attributes['forum.node_id'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Content pipeline forum node id attribute is invalid.');
        }
        return EntityId::fromString($value);
    }
}
