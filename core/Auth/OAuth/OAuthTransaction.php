<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class OAuthTransaction
{
    public function __construct(
        public string $stateHash,
        public string $providerId,
        public string $codeVerifier,
        public string $redirectUri,
        public ?EntityId $intendedUserId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $stateHash) !== 1) {
            throw new OAuthException('OAuth transaction state hash is invalid.');
        }
        Pkce::challenge($codeVerifier);
        if ($expiresAt <= $createdAt) {
            throw new OAuthException('OAuth transaction expiry must be after creation.');
        }
    }
}
