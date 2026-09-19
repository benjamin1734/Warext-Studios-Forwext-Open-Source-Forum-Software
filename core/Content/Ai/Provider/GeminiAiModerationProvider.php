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
        $candidates = $response['candidates'] ?? null;
        if (!is_array($candidates) || !array_is_list($candidates) || !isset($candidates[0]) || !is_array($candidates[0])) {
            throw new AiModerationProviderException('Gemini moderation response candidates are invalid.');
        }
        $content = $candidates[0]['content'] ?? null;
        $parts = is_array($content) ? ($content['parts'] ?? null) : null;
        $first = is_array($parts) && array_is_list($parts) && isset($parts[0]) && is_array($parts[0])
            ? $parts[0]
            : null;
        $text = is_array($first) ? ($first['text'] ?? null) : null;
        if (!is_string($text) || trim($text) === '') {
            throw new AiModerationProviderException('Gemini moderation response is missing classifier JSON.');
        }
        return $text;
    }
}
