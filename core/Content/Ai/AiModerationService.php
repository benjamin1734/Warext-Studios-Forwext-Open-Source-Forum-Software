<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final readonly class AiModerationService
{
    public function __construct(
        private AiModerationProviderRegistry $providers,
        private string $providerKey,
        private int $timeoutMilliseconds = 4000,
        private float $fallbackRiskScore = 0.5,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->providerKey) !== 1) {
            throw new InvalidArgumentException('AI moderation selected provider key is invalid.');
        }
        if ($this->timeoutMilliseconds < 250 || $this->timeoutMilliseconds > 15000) {
            throw new InvalidArgumentException('AI moderation timeout must be between 250 and 15000 milliseconds.');
        }
        if (!is_finite($this->fallbackRiskScore)
            || $this->fallbackRiskScore < 0.0
            || $this->fallbackRiskScore > 1.0
        ) {
            throw new InvalidArgumentException('AI moderation fallback risk score is invalid.');
        }
    }

    public function evaluate(AiModerationRequest $request): AiModerationAssessment
    {
        $provider = $this->providers->require($this->providerKey);
        try {
            return $provider->assess($request, $this->timeoutMilliseconds);
        } catch (AiModerationTimeoutException) {
            return AiModerationAssessment::fallback(
                $provider->key(),
                $provider->model(),
                'timeout',
                $this->fallbackRiskScore,
            );
        } catch (AiModerationProviderException) {
            return AiModerationAssessment::fallback(
                $provider->key(),
                $provider->model(),
                'provider_error',
                $this->fallbackRiskScore,
            );
        }
    }
}
