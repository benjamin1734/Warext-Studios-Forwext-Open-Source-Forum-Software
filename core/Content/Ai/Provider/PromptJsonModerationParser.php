<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Provider;

use Forwext\Core\Content\Ai\AiModerationAssessment;
use Forwext\Core\Content\Ai\AiModerationProviderException;
use JsonException;

final class PromptJsonModerationParser
{
    public const SYSTEM_PROMPT = 'Classify the supplied forum content for moderation risk. '
        . 'Return JSON only with this exact shape: '
        . '{"risk_score":0.0,"categories":{"violence":0.0,"hate":0.0,"harassment":0.0,'
        . '"sexual":0.0,"self_harm":0.0,"illegal":0.0,"spam":0.0}}. '
        . 'Every score must be a number from 0 to 1. risk_score must be the overall moderation risk. '
        . 'Do not include explanations or markdown.';

    public static function assessment(
        string $text,
        string $providerKey,
        string $model,
    ): AiModerationAssessment {
        $text = trim($text);
        try {
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiModerationProviderException('AI moderation classifier returned invalid JSON.', previous: $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new AiModerationProviderException('AI moderation classifier response must be an object.');
        }

        $risk = AiModerationProviderSupport::score($decoded['risk_score'] ?? null);
        $rawCategories = $decoded['categories'] ?? [];
        if (!is_array($rawCategories) || array_is_list($rawCategories)) {
            throw new AiModerationProviderException('AI moderation classifier categories are invalid.');
        }
        $categories = [];
        foreach ($rawCategories as $key=>$score) {
            if (!is_string($key)) {
                throw new AiModerationProviderException('AI moderation classifier category key is invalid.');
            }
            $categories[AiModerationProviderSupport::categoryKey($key)] =
                AiModerationProviderSupport::score($score);
        }
        ksort($categories, SORT_STRING);

        return new AiModerationAssessment($providerKey, $model, $risk, $categories);
    }
}
