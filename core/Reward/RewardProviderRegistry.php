<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use InvalidArgumentException;

final class RewardProviderRegistry
{
    /** @var array<string,RewardProvider> */
    private array $providers = [];

    /** @param iterable<RewardProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) $this->register($provider);
    }

    public function register(RewardProvider $provider): void
    {
        $key = $provider->key();
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $key) !== 1 || isset($this->providers[$key])) {
            throw new InvalidArgumentException('Reward provider key is invalid or duplicated.');
        }
        $this->providers[$key] = $provider;
    }

    public function find(string $key): ?RewardProvider
    {
        return $this->providers[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->providers);
        sort($keys, SORT_STRING);
        return $keys;
    }
}
