<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use InvalidArgumentException;

final class AiModerationProviderRegistry
{
    /** @var array<string, AiModerationProvider> */
    private array $providers = [];

    /**
     * @param iterable<AiModerationProvider> $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(AiModerationProvider $provider): void
    {
        $key = $provider->key();
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('AI moderation provider registry key is invalid.');
        }
        if (isset($this->providers[$key])) {
            throw new InvalidArgumentException('AI moderation provider is already registered: ' . $key);
        }
        $this->providers[$key] = $provider;
    }

    public function require(string $key): AiModerationProvider
    {
        return $this->providers[$key]
            ?? throw new AiModerationProviderException('AI moderation provider is not registered: ' . $key);
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->providers);
        sort($keys, SORT_STRING);
        return $keys;
    }
}
