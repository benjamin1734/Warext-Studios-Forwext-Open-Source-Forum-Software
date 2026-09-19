<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final readonly class AiModerationAssessment
{
    public AiModerationUsage $usage;

    /**
     * @param array<string, float> $categories
     */
    public function __construct(
        public string $providerKey,
        public string $model,
        public float $riskScore,
        public array $categories = [],
        public ?string $fallbackReason = null,
        ?AiModerationUsage $usage = null,
        public string $promptVersion = 'core.v1',
        public bool $redacted = false,
    ) {
        $this->usage = $usage ?? new AiModerationUsage();
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->providerKey) !== 1) {
            throw new InvalidArgumentException('AI moderation provider key is invalid.');
        }
        if ($this->model === '' || strlen($this->model) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $this->model) === 1
        ) {
            throw new InvalidArgumentException('AI moderation model is invalid.');
        }
        if (!is_finite($this->riskScore) || $this->riskScore < 0.0 || $this->riskScore > 1.0) {
            throw new InvalidArgumentException('AI moderation risk score must be between 0 and 1.');
        }
        foreach ($this->categories as $key => $score) {
            if (!is_string($key)
                || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $key) !== 1
                || !is_float($score)
                || !is_finite($score)
                || $score < 0.0
                || $score > 1.0
            ) {
                throw new InvalidArgumentException('AI moderation category scores are invalid.');
            }
        }
        if ($this->fallbackReason !== null
            && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->fallbackReason) !== 1
        ) {
            throw new InvalidArgumentException('AI moderation fallback reason is invalid.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/D', $this->promptVersion) !== 1) {
            throw new InvalidArgumentException('AI moderation prompt version is invalid.');
        }
    }

    public function withOperationalMetadata(
        AiModerationUsage $usage,
        string $promptVersion,
        bool $redacted,
    ): self {
        return new self(
            $this->providerKey,
            $this->model,
            $this->riskScore,
            $this->categories,
            $this->fallbackReason,
            $usage,
            $promptVersion,
            $redacted,
        );
    }

    public static function fallback(
        string $providerKey,
        string $model,
        string $reason,
        float $riskScore = 0.5,
        ?AiModerationUsage $usage = null,
        string $promptVersion = 'core.v1',
        bool $redacted = false,
    ): self {
        return new self($providerKey, $model, $riskScore, [], $reason, $usage, $promptVersion, $redacted);
    }
}
