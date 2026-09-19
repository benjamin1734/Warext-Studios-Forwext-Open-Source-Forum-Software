<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Provider;

use Forwext\Core\Content\Ai\AiModerationCredentialStore;
use Forwext\Core\Content\Ai\AiModerationProvider;
use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Content\Ai\Transport\AiModerationEndpointPolicy;
use Forwext\Core\Content\Ai\Transport\AiModerationHttpTransport;
use SensitiveParameter;

final readonly class CoreAiModerationProviderFactory
{
    public function __construct(
        private AiModerationEndpointPolicy $endpoints,
        private AiModerationHttpTransport $transport,
    ) {
    }

    public function openAi(#[SensitiveParameter] string $apiKey, string $model): AiModerationProvider
    {
        return new OpenAiModerationProvider(
            $this->transport,
            $this->endpoints->approve('https://api.openai.com/v1/moderations'),
            $apiKey,
            $model,
        );
    }

    public function gemini(#[SensitiveParameter] string $apiKey, string $model): AiModerationProvider
    {
        $model = AiModerationProviderSupport::assertModel($model);
        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
            $model = AiModerationProviderSupport::assertModel($model);
        }
        return new GeminiAiModerationProvider(
            $this->transport,
            $this->endpoints->approve(
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            ),
            $apiKey,
            $model,
        );
    }

    public function anthropic(#[SensitiveParameter] string $apiKey, string $model): AiModerationProvider
    {
        return new AnthropicAiModerationProvider(
            $this->transport,
            $this->endpoints->approve('https://api.anthropic.com/v1/messages'),
            $apiKey,
            $model,
        );
    }

    public function openRouter(#[SensitiveParameter] string $apiKey, string $model): AiModerationProvider
    {
        return new OpenRouterAiModerationProvider(
            $this->transport,
            $this->endpoints->approve('https://openrouter.ai/api/v1/chat/completions'),
            $apiKey,
            $model,
        );
    }

    public function custom(
        string $endpoint,
        string $providerKey,
        string $model,
        #[SensitiveParameter] ?string $credential = null,
    ): AiModerationProvider {
        return new CustomAiModerationProvider(
            $this->transport,
            $this->endpoints->approve($endpoint),
            $providerKey,
            $model,
            $credential,
        );
    }

    public function fromCredentialStore(
        AiModerationCredentialStore $credentials,
        string $providerKey,
        string $model,
        ?string $customEndpoint = null,
    ): AiModerationProvider {
        $credential = $credentials->get($providerKey);
        if ($credential === null) {
            throw new AiModerationProviderException('AI moderation provider credential is unavailable.');
        }
        return match ($providerKey) {
            'openai' => $this->openAi($credential, $model),
            'gemini' => $this->gemini($credential, $model),
            'anthropic' => $this->anthropic($credential, $model),
            'openrouter' => $this->openRouter($credential, $model),
            default => $customEndpoint === null
                ? throw new AiModerationProviderException('Custom AI provider endpoint is required.')
                : $this->custom($customEndpoint, $providerKey, $model, $credential),
        };
    }
}
