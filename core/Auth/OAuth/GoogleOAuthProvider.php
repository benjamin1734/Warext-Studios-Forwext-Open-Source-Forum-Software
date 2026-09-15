<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use SensitiveParameter;

final readonly class GoogleOAuthProvider implements OAuthProvider
{
    public function id(): string { return 'google'; }

    public function authorizationUrl(OAuthProviderConfig $config, string $redirectUri, string $state, string $codeChallenge): string
    {
        $config->assertUsable($redirectUri);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $config->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function resolveIdentity(OAuthProviderConfig $config, #[SensitiveParameter] string $clientSecret, string $redirectUri, string $code, #[SensitiveParameter] string $codeVerifier, OAuthHttpClient $http): OAuthIdentity
    {
        $config->assertUsable($redirectUri);
        $token = $http->postForm('https://oauth2.googleapis.com/token', [
            'client_id' => $config->clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);
        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new OAuthException('Google token response did not contain an access token.');
        }
        $profile = $http->getBearerJson('https://openidconnect.googleapis.com/v1/userinfo', $accessToken);
        $subject = $profile['sub'] ?? null;
        if (!is_string($subject)) {
            throw new OAuthException('Google profile did not contain a subject identifier.');
        }
        $email = isset($profile['email']) && is_string($profile['email']) ? $profile['email'] : null;
        $verified = ($profile['email_verified'] ?? false) === true;
        $name = isset($profile['name']) && is_string($profile['name']) ? $profile['name'] : null;
        return new OAuthIdentity($this->id(), $subject, $email, $verified, $name);
    }
}
