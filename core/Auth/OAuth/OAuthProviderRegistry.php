<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

final class OAuthProviderRegistry
{
    /** @var array<string, OAuthProvider> */
    private array $providers = [];
    /** @var array<string, OAuthProviderConfig> */
    private array $configs = [];

    /** @param list<OAuthProvider> $providers @param list<OAuthProviderConfig> $configs */
    public function __construct(array $providers, array $configs)
    {
        foreach ($providers as $provider) {
            if (isset($this->providers[$provider->id()])) {
                throw new OAuthException('Duplicate OAuth provider registration.');
            }
            $this->providers[$provider->id()] = $provider;
        }
        foreach ($configs as $config) {
            if (isset($this->configs[$config->providerId])) {
                throw new OAuthException('Duplicate OAuth provider configuration.');
            }
            $this->configs[$config->providerId] = $config;
        }
    }

    /** @return array{0: OAuthProvider, 1: OAuthProviderConfig} */
    public function resolve(string $providerId): array
    {
        $provider = $this->providers[$providerId] ?? null;
        $config = $this->configs[$providerId] ?? null;
        if (!$provider instanceof OAuthProvider || !$config instanceof OAuthProviderConfig) {
            throw new OAuthException('OAuth provider is not configured.');
        }
        return [$provider, $config];
    }
}
