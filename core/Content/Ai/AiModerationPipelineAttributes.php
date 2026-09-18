<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use Forwext\Core\Content\Pipeline\ContentPipelineContext;
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
            ->withAttribute('ai.fallback_reason', $assessment->fallbackReason);
    }

    public static function assessment(ContentPipelineContext $context): AiModerationAssessment
    {
        $provider = $context->attributes['ai.provider'] ?? null;
        $model = $context->attributes['ai.model'] ?? null;
        $risk = $context->attributes['ai.risk_score'] ?? null;
        $fallback = $context->attributes['ai.fallback_reason'] ?? null;
        if (!is_string($provider) || !is_string($model)
            || (!is_float($risk) && !is_int($risk))
            || ($fallback !== null && !is_string($fallback))
        ) {
            throw new InvalidArgumentException('AI moderation pipeline assessment attributes are invalid.');
        }
        return new AiModerationAssessment($provider, $model, (float) $risk, [], $fallback);
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
}
