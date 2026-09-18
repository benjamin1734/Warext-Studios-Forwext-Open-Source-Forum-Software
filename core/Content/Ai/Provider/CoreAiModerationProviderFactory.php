<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Provider;

use Forwext\Core\Content\Ai\AiModerationProvider;
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
}
