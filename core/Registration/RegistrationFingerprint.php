<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Security\Secret\SecretStore;

final readonly class RegistrationFingerprint
{
    public function __construct(
        private SecretStore $secrets,
        private string $secretName = 'registration.fingerprint_key',
    ) {
    }

    public function ip(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            throw new RegistrationException('Registration client IP is invalid.');
        }
        return $this->hash('ip:' . bin2hex($packed));
    }

    public function email(EmailAddress $email): string
    {
        return $this->hash('email:' . $email->key());
    }

    private function hash(string $value): string
    {
        $secret = $this->secrets->get($this->secretName);
        if ($secret === null || strlen($secret) < 32) {
            throw new RegistrationException('Registration fingerprint secret is not configured securely.');
        }
        return hash_hmac('sha256', $value, $secret);
    }
}
