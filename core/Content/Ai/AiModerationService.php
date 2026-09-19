<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final readonly class AiModerationService
{
    private AiModerationTextRedactor $redactor;
    private AiModerationPromptRegistry $prompts;
    private AiModerationCostPolicy $costPolicy;

    public function __construct(
        private AiModerationProviderRegistry $providers,
        private string $providerKey,
        private int $timeoutMilliseconds = 4000,
        private float $fallbackRiskScore = 0.5,
        ?AiModerationTextRedactor $redactor = null,
        ?AiModerationPromptRegistry $prompts = null,
        ?AiModerationCostPolicy $costPolicy = null,
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
        $this->redactor = $redactor ?? new AiModerationTextRedactor();
        $this->prompts = $prompts ?? new AiModerationPromptRegistry();
        $this->costPolicy = $costPolicy ?? new AiModerationCostPolicy();
    }

    public function evaluate(AiModerationRequest $request): AiModerationAssessment
    {
        return $this->evaluateConfigured(
            $request,
            $this->providerKey,
            $request->prompt,
            true,
            $this->costPolicy,
        );
    }

    public function evaluateForPolicy(
        AiModerationRequest $request,
        ?AiModerationForumPolicy $policy,
    ): AiModerationAssessment {
        if ($policy !== null && !$policy->enabled) {
            return new AiModerationAssessment(
                'policy',
                'disabled',
                0.0,
                [],
                null,
                new AiModerationUsage(),
                $policy->promptVersion,
                false,
            );
        }

        return $this->evaluateConfigured(
            $request,
            $policy?->providerKey ?? $this->providerKey,
            $policy === null ? $request->prompt : $this->prompts->require($policy->promptVersion),
            $policy?->redactSensitiveData ?? true,
            $policy?->costPolicy() ?? $this->costPolicy,
        );
    }

    private function evaluateConfigured(
        AiModerationRequest $request,
        string $providerKey,
        AiModerationPrompt $prompt,
        bool $redactSensitiveData,
        AiModerationCostPolicy $costPolicy,
    ): AiModerationAssessment {
        $provider = $this->providers->require($providerKey);
        $redaction = $redactSensitiveData
            ? $this->redactor->redact($request->text)
            : new AiModerationRedactionResult($request->text, false);
        $providerRequest = new AiModerationRequest($request->contentType, $redaction->text, $prompt);

        try {
            $assessment = $provider->assess($providerRequest, $this->timeoutMilliseconds);
            return $assessment->withOperationalMetadata(
                $costPolicy->price($assessment->usage),
                $prompt->version,
                $redaction->redacted,
            );
        } catch (AiModerationTimeoutException) {
            return AiModerationAssessment::fallback(
                $provider->key(),
                $provider->model(),
                'timeout',
                $this->fallbackRiskScore,
                new AiModerationUsage(),
                $prompt->version,
                $redaction->redacted,
            );
        } catch (AiModerationProviderException) {
            return AiModerationAssessment::fallback(
                $provider->key(),
                $provider->model(),
                'provider_error',
                $this->fallbackRiskScore,
                new AiModerationUsage(),
                $prompt->version,
                $redaction->redacted,
            );
        }
    }
}
