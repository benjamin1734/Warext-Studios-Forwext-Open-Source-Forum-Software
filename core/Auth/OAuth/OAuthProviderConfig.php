<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

final readonly class OAuthProviderConfig
{
    /** @param list<string> $redirectUris */
    public function __construct(
        public string $providerId,
        public bool $enabled,
        public string $clientId,
        public string $clientSecretName,
        public array $redirectUris,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $providerId) !== 1) {
            throw new OAuthException('OAuth provider id is invalid.');
        }
        if ($clientSecretName === '' || strlen($clientSecretName) > 191) {
            throw new OAuthException('OAuth client secret reference is invalid.');
        }
        foreach ($redirectUris as $uri) {
            self::assertHttpsUri($uri);
        }
    }

    public function assertUsable(string $redirectUri): void
    {
        if (!$this->enabled) {
            throw new OAuthException(sprintf('OAuth provider "%s" is disabled.', $this->providerId));
        }
        if (trim($this->clientId) === '') {
            throw new OAuthException(sprintf('OAuth provider "%s" has no client id.', $this->providerId));
        }
        self::assertHttpsUri($redirectUri);
        if (!in_array($redirectUri, $this->redirectUris, true)) {
            throw new OAuthException('OAuth redirect URI is not allowlisted.');
        }
    }

    private static function assertHttpsUri(string $uri): void
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])) {
            throw new OAuthException('OAuth redirect URI must be an absolute HTTPS URL.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new OAuthException('OAuth redirect URI may not contain credentials or a fragment.');
        }
    }
}
