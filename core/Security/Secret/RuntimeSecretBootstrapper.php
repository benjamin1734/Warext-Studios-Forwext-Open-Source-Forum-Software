<?php

declare(strict_types=1);

namespace Forwext\Core\Security\Secret;

final readonly class RuntimeSecretBootstrapper
{
    public const AUTHENTICATION_FINGERPRINT = 'authentication.fingerprint_key';
    public const REGISTRATION_FINGERPRINT = 'registration.fingerprint_key';

    public function __construct(
        private SecretStore $secrets,
        private SecretKey $masterKey,
    ) {
    }

    public function ensure(): void
    {
        foreach ([
            self::AUTHENTICATION_FINGERPRINT => 'authentication-fingerprint.v1',
            self::REGISTRATION_FINGERPRINT => 'registration-fingerprint.v1',
        ] as $name => $domain) {
            $existing = $this->secrets->get($name);
            if (is_string($existing) && strlen($existing) >= 32) {
                continue;
            }

            $derived = hash_hmac(
                'sha256',
                'forwext.runtime-secret.' . $domain,
                $this->masterKey->bytesForCrypto(),
                true,
            );
            $this->secrets->set($name, base64_encode($derived));
        }
    }
}
