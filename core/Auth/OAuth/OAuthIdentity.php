<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

final readonly class OAuthIdentity
{
    public function __construct(
        public string $providerId,
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $displayName = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $providerId) !== 1) {
            throw new OAuthException('OAuth identity provider id is invalid.');
        }
        if ($subject === '' || strlen($subject) > 191 || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            throw new OAuthException('OAuth identity subject is invalid.');
        }
        if ($email !== null && strlen($email) > 254) {
            throw new OAuthException('OAuth identity email is too long.');
        }
        if ($displayName !== null && strlen($displayName) > 191) {
            throw new OAuthException('OAuth identity display name is too long.');
        }
    }
}
