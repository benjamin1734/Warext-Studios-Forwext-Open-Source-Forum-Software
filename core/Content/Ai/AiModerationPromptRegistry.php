<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final class AiModerationPromptRegistry
{
    /** @var array<string,AiModerationPrompt> */
    private array $prompts = [];

    /** @param iterable<AiModerationPrompt> $prompts */
    public function __construct(iterable $prompts = [])
    {
        $this->register(AiModerationPrompt::coreV1());
        foreach ($prompts as $prompt) {
            if ($prompt->version === 'core.v1') {
                continue;
            }
            $this->register($prompt);
        }
    }

    public function register(AiModerationPrompt $prompt): void
    {
        if (isset($this->prompts[$prompt->version])) {
            throw new InvalidArgumentException('AI moderation prompt version is already registered.');
        }
        $this->prompts[$prompt->version] = $prompt;
    }

    public function require(string $version): AiModerationPrompt
    {
        return $this->prompts[$version]
            ?? throw new InvalidArgumentException('AI moderation prompt version is not registered.');
    }

    /** @return list<string> */
    public function versions(): array
    {
        $versions = array_keys($this->prompts);
        sort($versions, SORT_STRING);
        return $versions;
    }
}
