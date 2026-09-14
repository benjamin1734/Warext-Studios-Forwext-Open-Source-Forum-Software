<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\Csrf;

use Forwext\Core\Security\Secret\SecretKey;

final readonly class CsrfTokenManager
{
    private const VERSION = 'v1';

    public function __construct(
        private SecretKey $key,
        private int $ttlSeconds = 7200,
        private int $futureSkewSeconds = 60,
    ) {
        if ($ttlSeconds < 60) {
            throw new CsrfException('CSRF token lifetime must be at least 60 seconds.');
        }
        if ($futureSkewSeconds < 0 || $futureSkewSeconds > 300) {
            throw new CsrfException('CSRF future clock skew must be between 0 and 300 seconds.');
        }
    }

    public function issue(string $contextId, string $scope = 'web', ?int $now = null): string
    {
        $this->assertContext($contextId);
        $this->assertScope($scope);

        $timestamp = $now ?? time();
        $nonce = bin2hex(random_bytes(16));
        $mac = hash_hmac('sha256', $this->payload($timestamp, $nonce, $contextId, $scope), $this->key->bytesForCrypto());

        return implode('.', [self::VERSION, (string) $timestamp, $nonce, $mac]);
    }

    public function verify(
        string $token,
        string $contextId,
        string $scope = 'web',
        ?int $now = null,
    ): bool {
        $this->assertContext($contextId);
        $this->assertScope($scope);

        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION || !ctype_digit($parts[1])) {
            return false;
        }

        [$version, $timestampText, $nonce, $mac] = $parts;
        unset($version);

        if (
            preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $mac) !== 1
        ) {
            return false;
        }

        $timestamp = (int) $timestampText;
        $clock = $now ?? time();
        if ($timestamp > $clock + $this->futureSkewSeconds || $clock - $timestamp > $this->ttlSeconds) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $this->payload($timestamp, $nonce, $contextId, $scope),
            $this->key->bytesForCrypto(),
        );

        return hash_equals($expected, $mac);
    }

    private function payload(int $timestamp, string $nonce, string $contextId, string $scope): string
    {
        return implode('|', [self::VERSION, (string) $timestamp, $nonce, $contextId, $scope]);
    }

    private function assertContext(string $contextId): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{16,191}$/D', $contextId) !== 1) {
            throw new CsrfException('CSRF context identifier is invalid.');
        }
    }

    private function assertScope(string $scope): void
    {
        if ($scope === '' || strlen($scope) > 191 || preg_match('/[\x00-\x1F\x7F]/', $scope) === 1) {
            throw new CsrfException('CSRF scope is invalid.');
        }
    }
}
