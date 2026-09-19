<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Provider;

use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Content\Ai\AiModerationRequest;
use Forwext\Core\Content\Ai\AiModerationUsage;

final class AnthropicAiModerationProvider extends AbstractPromptAiModerationProvider
{
    public function key(): string
    {
        return 'anthropic';
    }

    protected function headers(): array
    {
        return [
            'x-api-key'=>$this->credential,
            'anthropic-version'=>'2023-06-01',
        ];
    }

    protected function payload(AiModerationRequest $request): array
    {
        return [
            'model'=>$this->providerModel,
            'max_tokens'=>400,
            'system'=>$request->prompt->systemPrompt,
            'messages'=>[[
                'role'=>'user',
                'content'=>$request->text,
            ]],
        ];
    }

    protected function responseText(array $response): string
    {
        $content = $response['content'] ?? null;
        if (!is_array($content)) {
            throw new AiModerationProviderException('Anthropic moderation response content is invalid.');
        }
        foreach ($content as $part) {
            if (is_array($part) && ($part['type'] ?? null) === 'text'
                && is_string($part['text'] ?? null) && trim((string) $part['text']) !== ''
            ) {
                return (string) $part['text'];
            }
        }
        throw new AiModerationProviderException('Anthropic moderation response is missing classifier JSON.');
    }

    protected function usage(array $response): AiModerationUsage
    {
        return AiModerationProviderSupport::anthropicUsage($response);
    }
}
