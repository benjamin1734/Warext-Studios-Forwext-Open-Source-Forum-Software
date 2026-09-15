<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use SensitiveParameter;

interface OAuthProvider
{
    public function id(): string;

    public function authorizationUrl(
        OAuthProviderConfig $config,
        string $redirectUri,
        string $state,
        string $codeChallenge,
    ): string;

    public function resolveIdentity(
        OAuthProviderConfig $config,
        #[SensitiveParameter] string $clientSecret,
        string $redirectUri,
        string $code,
        #[SensitiveParameter] string $codeVerifier,
        OAuthHttpClient $http,
    ): OAuthIdentity;
}
