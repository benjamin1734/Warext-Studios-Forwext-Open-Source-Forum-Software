<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

final class OAuthProviderRegistryFactory
{
    /** @param array<string, mixed> $oauthConfig */
    public static function fromConfig(array $oauthConfig): OAuthProviderRegistry
    {
        $providerConfig = $oauthConfig['providers'] ?? [];
        if (!is_array($providerConfig)) {
            throw new OAuthException('OAuth provider configuration must be an array.');
        }

        return new OAuthProviderRegistry(
            [new GoogleOAuthProvider(), new DiscordOAuthProvider()],
            [
                self::provider('google', $providerConfig['google'] ?? []),
                self::provider('discord', $providerConfig['discord'] ?? []),
            ],
        );
    }

    /** @param mixed $raw */
    private static function provider(string $id, mixed $raw): OAuthProviderConfig
    {
        if (!is_array($raw)) {
            throw new OAuthException(sprintf('OAuth provider "%s" configuration must be an array.', $id));
        }
        $redirectUris = $raw['redirect_uris'] ?? [];
        if (!is_array($redirectUris) || array_filter($redirectUris, 'is_string') !== $redirectUris) {
            throw new OAuthException(sprintf('OAuth provider "%s" redirect URIs are invalid.', $id));
        }
        return new OAuthProviderConfig(
            $id,
            ($raw['enabled'] ?? false) === true,
            is_string($raw['client_id'] ?? null) ? $raw['client_id'] : '',
            is_string($raw['client_secret_name'] ?? null)
                ? $raw['client_secret_name']
                : 'oauth.' . $id . '.client_secret',
            array_values($redirectUris),
        );
    }
}
