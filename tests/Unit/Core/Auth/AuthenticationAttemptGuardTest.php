<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Login\AuthenticationAttemptGuard;
use Forwext\Core\Auth\Login\AuthenticationRateLimiter;
use PHPUnit\Framework\TestCase;

final class AuthenticationAttemptGuardTest extends TestCase
{
    public function testIdentityAndNetworkUseIndependentBucketsAndLimits(): void
    {
        $limiter = new RecordingAuthenticationRateLimiter();
        $guard = new AuthenticationAttemptGuard($limiter, 10, 50, 900);
        $now = new DateTimeImmutable('2026-09-15 08:00:00', new DateTimeZone('UTC'));
        $identity = str_repeat('a', 64);
        $network = str_repeat('b', 64);

        self::assertTrue($guard->allows($identity, $network, $now));
        self::assertCount(2, $limiter->calls);
        self::assertSame(10, $limiter->calls[0]['limit']);
        self::assertSame(50, $limiter->calls[1]['limit']);
        self::assertNotSame($limiter->calls[0]['fingerprint'], $limiter->calls[1]['fingerprint']);
        self::assertSame(hash('sha256', 'identity:' . $identity), $limiter->calls[0]['fingerprint']);
        self::assertSame(hash('sha256', 'network:' . $network), $limiter->calls[1]['fingerprint']);
    }

    public function testDenialOfEitherBucketRejectsAttemptWhileBothBucketsAreConsumed(): void
    {
        $limiter = new RecordingAuthenticationRateLimiter([true, false]);
        $guard = new AuthenticationAttemptGuard($limiter, 10, 50, 900);

        self::assertFalse($guard->allows(
            str_repeat('c', 64),
            str_repeat('d', 64),
            new DateTimeImmutable('2026-09-15 08:00:00', new DateTimeZone('UTC')),
        ));
        self::assertCount(2, $limiter->calls);
    }
}

final class RecordingAuthenticationRateLimiter implements AuthenticationRateLimiter
{
    /** @var list<array{fingerprint:string,limit:int,window:int}> */
    public array $calls = [];

    /** @var list<bool> */
    private array $results;

    /** @param list<bool> $results */
    public function __construct(array $results = [true, true])
    {
        $this->results = $results;
    }

    public function consume(string $fingerprint, int $limit, int $windowSeconds, DateTimeImmutable $now): bool
    {
        $this->calls[] = [
            'fingerprint' => $fingerprint,
            'limit' => $limit,
            'window' => $windowSeconds,
        ];

        return array_shift($this->results) ?? true;
    }
}
