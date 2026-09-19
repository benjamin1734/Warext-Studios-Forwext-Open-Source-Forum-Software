<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Provider;

use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Content\Ai\AiModerationUsage;
use Forwext\Core\Content\Ai\Transport\AiModerationHttpResponse;
use JsonException;

final class AiModerationProviderSupport
{
    public static function assertModel(string $model): string
    {
        $model = trim($model);
        if ($model === '' || strlen($model) > 191 || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
            throw new AiModerationProviderException('AI moderation model identifier is invalid.');
        }
        return $model;
    }

    public static function assertCredential(string $credential): string
    {
        if ($credential === '' || strlen($credential) > 8192 || preg_match('/[\r\n\x00]/', $credential) === 1) {
            throw new AiModerationProviderException('AI moderation provider credential is invalid.');
        }
        return $credential;
    }

    /** @param array<string,mixed> $value */
    public static function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new AiModerationProviderException('AI moderation request could not be encoded.', previous: $exception);
        }
    }

    /** @return array<string,mixed> */
    public static function object(AiModerationHttpResponse $response): array
    {
        if ($response->status < 200 || $response->status >= 300) {
            throw new AiModerationProviderException('AI moderation provider returned a non-success HTTP status.');
        }
        try {
            $decoded = json_decode($response->body, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiModerationProviderException('AI moderation provider returned invalid JSON.', previous: $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new AiModerationProviderException('AI moderation provider response must be a JSON object.');
        }
        return $decoded;
    }

    public static function categoryKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '_', $value) ?? '';
        $value = trim($value, '._-');
        if ($value === '' || ctype_digit($value[0])) {
            $value = 'risk_' . $value;
        }
        $value = substr($value, 0, 64);
        if (strlen($value) < 2 || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $value) !== 1) {
            throw new AiModerationProviderException('AI moderation category key is invalid.');
        }
        return $value;
    }

    public static function score(mixed $value): float
    {
        if (!is_float($value) && !is_int($value)) {
            throw new AiModerationProviderException('AI moderation risk score is invalid.');
        }
        $score = (float) $value;
        if (!is_finite($score) || $score < 0.0 || $score > 1.0) {
            throw new AiModerationProviderException('AI moderation risk score is outside 0..1.');
        }
        return $score;
    }

    /** @param array<string,mixed> $response */
    public static function openAiLikeUsage(array $response): AiModerationUsage
    {
        $usage = $response['usage'] ?? null;
        if (!is_array($usage)) {
            return new AiModerationUsage();
        }
        return new AiModerationUsage(
            self::nonNegativeInt($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            self::nonNegativeInt($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
        );
    }

    /** @param array<string,mixed> $response */
    public static function anthropicUsage(array $response): AiModerationUsage
    {
        $usage = $response['usage'] ?? null;
        if (!is_array($usage)) {
            return new AiModerationUsage();
        }
        return new AiModerationUsage(
            self::nonNegativeInt($usage['input_tokens'] ?? 0),
            self::nonNegativeInt($usage['output_tokens'] ?? 0),
        );
    }

    /** @param array<string,mixed> $response */
    public static function geminiUsage(array $response): AiModerationUsage
    {
        $usage = $response['usageMetadata'] ?? null;
        if (!is_array($usage)) {
            return new AiModerationUsage();
        }
        return new AiModerationUsage(
            self::nonNegativeInt($usage['promptTokenCount'] ?? 0),
            self::nonNegativeInt($usage['candidatesTokenCount'] ?? 0),
        );
    }

    private static function nonNegativeInt(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 100_000_000) {
            return 0;
        }
        return $value;
    }
}
