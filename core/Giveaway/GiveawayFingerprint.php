<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use Forwext\Core\Security\Secret\SecretStore;
use InvalidArgumentException;
use RuntimeException;

final readonly class GiveawayFingerprint
{
    public function __construct(
        private SecretStore $secrets,
        private string $secretName = 'registration.fingerprint_key',
    ) {
    }

    public function network(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            throw new InvalidArgumentException('Giveaway client IP is invalid.');
        }
        return $this->hash('giveaway-network:' . bin2hex($packed));
    }

    public function device(string $ip, string $userAgent): string
    {
        $packed = @inet_pton($ip);
        $userAgent = trim($userAgent);
        if ($packed === false || $userAgent === '' || strlen($userAgent) > 1024
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $userAgent) === 1
        ) {
            throw new InvalidArgumentException('Giveaway device signal is invalid.');
        }
        return $this->hash('giveaway-device:' . bin2hex($packed) . ':' . $userAgent);
    }

    private function hash(string $value): string
    {
        $secret = $this->secrets->get($this->secretName);
        if ($secret === null || strlen($secret) < 32) {
            throw new RuntimeException('Giveaway fingerprint secret is not configured securely.');
        }
        return hash_hmac('sha256', $value, $secret);
    }
}
