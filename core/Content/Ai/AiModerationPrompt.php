<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final readonly class AiModerationPrompt
{
    public function __construct(
        public string $version,
        public string $systemPrompt,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/D', $this->version) !== 1) {
            throw new InvalidArgumentException('AI moderation prompt version is invalid.');
        }
        $length = strlen($this->systemPrompt);
        if ($length < 20 || $length > 12000 || preg_match('/[\x00\x08\x0B\x0C\x0E-\x1F\x7F]/', $this->systemPrompt) === 1) {
            throw new InvalidArgumentException('AI moderation system prompt is invalid.');
        }
    }

    public static function coreV1(): self
    {
        return new self(
            'core.v1',
            'Classify the supplied forum content for moderation risk. '
            . 'Return JSON only with this exact shape: '
            . '{"risk_score":0.0,"categories":{"violence":0.0,"hate":0.0,"harassment":0.0,'
            . '"sexual":0.0,"self_harm":0.0,"illegal":0.0,"spam":0.0}}. '
            . 'Every score must be a number from 0 to 1. risk_score must be the overall moderation risk. '
            . 'Do not include explanations or markdown.',
        );
    }
}
