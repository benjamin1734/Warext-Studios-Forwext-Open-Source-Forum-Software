<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Login;

use DateTimeImmutable;
use Forwext\Core\Auth\AuthException;

final readonly class AuthenticationAttemptGuard
{
    public function __construct(
        private AuthenticationRateLimiter $rateLimiter,
        private int $identityAttemptLimit = 10,
        private int $networkAttemptLimit = 50,
        private int $windowSeconds = 900,
    ) {
        if (
            $identityAttemptLimit < 1
            || $networkAttemptLimit < 1
            || $windowSeconds < 60
            || $windowSeconds > 86400
        ) {
            throw new AuthException('Authentication attempt guard configuration is invalid.');
        }
    }

    public function allows(string $identityFingerprint, string $ipFingerprint, DateTimeImmutable $now): bool
    {
        self::assertFingerprint($identityFingerprint);
        self::assertFingerprint($ipFingerprint);

        $identityBucket = hash('sha256', 'identity:' . $identityFingerprint);
        $networkBucket = hash('sha256', 'network:' . $ipFingerprint);

        $identityAllowed = $this->rateLimiter->consume(
            $identityBucket,
            $this->identityAttemptLimit,
            $this->windowSeconds,
            $now,
        );
        $networkAllowed = $this->rateLimiter->consume(
            $networkBucket,
            $this->networkAttemptLimit,
            $this->windowSeconds,
            $now,
        );

        return $identityAllowed && $networkAllowed;
    }

    private static function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new AuthException('Authentication fingerprint is invalid.');
        }
    }
}
