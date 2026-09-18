<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Provider;

use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Content\Ai\AiModerationRequest;

final class OpenRouterAiModerationProvider extends AbstractPromptAiModerationProvider
{
    public function key(): string
    {
        return 'openrouter';
    }

    protected function headers(): array
    {
        return ['Authorization'=>'Bearer ' . $this->credential];
    }

    protected function payload(AiModerationRequest $request): array
    {
        return [
            'model'=>$this->providerModel,
            'temperature'=>0,
            'messages'=>[
                ['role'=>'system','content'=>PromptJsonModerationParser::SYSTEM_PROMPT],
                ['role'=>'user','content'=>$request->text],
            ],
        ];
    }

    protected function responseText(array $response): string
    {
        $choices = $response['choices'] ?? null;
        if (!is_array($choices) || !array_is_list($choices) || !isset($choices[0]) || !is_array($choices[0])) {
            throw new AiModerationProviderException('OpenRouter moderation response choices are invalid.');
        }
        $message = $choices[0]['message'] ?? null;
        $text = is_array($message) ? ($message['content'] ?? null) : null;
        if (!is_string($text) || trim($text) === '') {
            throw new AiModerationProviderException('OpenRouter moderation response is missing classifier JSON.');
        }
        return $text;
    }
}
