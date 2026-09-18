<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Provider;

use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Content\Ai\AiModerationRequest;

final class GeminiAiModerationProvider extends AbstractPromptAiModerationProvider
{
    public function key(): string
    {
        return 'gemini';
    }

    protected function headers(): array
    {
        return ['x-goog-api-key'=>$this->credential];
    }

    protected function payload(AiModerationRequest $request): array
    {
        return [
            'systemInstruction'=>[
                'parts'=>[['text'=>PromptJsonModerationParser::SYSTEM_PROMPT]],
            ],
            'contents'=>[[
                'role'=>'user',
                'parts'=>[['text'=>$request->text]],
            ]],
            'generationConfig'=>[
                'temperature'=>0,
                'responseMimeType'=>'application/json',
            ],
        ];
    }

    protected function responseText(array $response): string
    {
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new AiModerationProviderException('Gemini moderation response is missing classifier JSON.');
        }
        return $text;
    }
}
