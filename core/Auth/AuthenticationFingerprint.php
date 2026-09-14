<?php

declare(strict_types=1);

namespace Forwext\Core\Auth;

use Forwext\Core\Security\Secret\SecretStore;
use SensitiveParameter;

final readonly class AuthenticationFingerprint
{
    public function __construct(
        private SecretStore $secrets,
        private string $secretName = 'authentication.fingerprint_key',
    ) {
    }

    public function identity(#[SensitiveParameter] string $identifier): string
    {
        $normalized = strtolower(trim($identifier));
        if ($normalized === '' || strlen($normalized) > 512) {
            throw new AuthException('Authentication identifier fingerprint input is invalid.');
        }
        return $this->hash('identity:' . $normalized);
    }

    public function ip(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            throw new AuthException('Authentication client IP is invalid.');
        }
        return $this->hash('ip:' . bin2hex($packed));
    }

    public function userAgent(string $userAgent): string
    {
        if ($userAgent === '' || strlen($userAgent) > 2048 || str_contains($userAgent, "\0")) {
            throw new AuthException('Authentication user-agent input is invalid.');
        }
        return $this->hash('ua:' . $userAgent);
    }

    private function hash(string $value): string
    {
        $secret = $this->secrets->get($this->secretName);
        if ($secret === null || strlen($secret) < 32) {
            throw new AuthException('Authentication fingerprint secret is not configured securely.');
        }
        return hash_hmac('sha256', $value, $secret);
    }
}
