<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

final class Pkce
{
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(48));
    }

    public static function challenge(string $verifier): string
    {
        if (strlen($verifier) < 43 || strlen($verifier) > 128 || preg_match('/^[A-Za-z0-9._~-]+$/D', $verifier) !== 1) {
            throw new OAuthException('PKCE verifier is invalid.');
        }
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function state(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public static function stateHash(string $state): string
    {
        if (strlen($state) < 32 || strlen($state) > 256 || preg_match('/^[A-Za-z0-9_-]+$/D', $state) !== 1) {
            throw new OAuthException('OAuth state is invalid.');
        }
        return hash('sha256', $state);
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
