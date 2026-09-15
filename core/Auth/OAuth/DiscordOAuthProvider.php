<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use SensitiveParameter;

final readonly class DiscordOAuthProvider implements OAuthProvider
{
    public function id(): string { return 'discord'; }

    public function authorizationUrl(OAuthProviderConfig $config, string $redirectUri, string $state, string $codeChallenge): string
    {
        $config->assertUsable($redirectUri);
        return 'https://discord.com/oauth2/authorize?' . http_build_query([
            'client_id' => $config->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'identify email',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function resolveIdentity(OAuthProviderConfig $config, #[SensitiveParameter] string $clientSecret, string $redirectUri, string $code, #[SensitiveParameter] string $codeVerifier, OAuthHttpClient $http): OAuthIdentity
    {
        $config->assertUsable($redirectUri);
        $token = $http->postForm('https://discord.com/api/oauth2/token', [
            'client_id' => $config->clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);
        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new OAuthException('Discord token response did not contain an access token.');
        }
        $profile = $http->getBearerJson('https://discord.com/api/users/@me', $accessToken);
        $subject = $profile['id'] ?? null;
        if (!is_string($subject)) {
            throw new OAuthException('Discord profile did not contain a subject identifier.');
        }
        $email = isset($profile['email']) && is_string($profile['email']) ? $profile['email'] : null;
        $verified = ($profile['verified'] ?? false) === true;
        $name = isset($profile['global_name']) && is_string($profile['global_name'])
            ? $profile['global_name']
            : (isset($profile['username']) && is_string($profile['username']) ? $profile['username'] : null);
        return new OAuthIdentity($this->id(), $subject, $email, $verified, $name);
    }
}
