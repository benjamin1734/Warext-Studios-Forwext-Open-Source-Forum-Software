<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

use InvalidArgumentException;

final readonly class AddonTrustedKey
{
    public function __construct(
        public string $id,
        public string $publicKeyPem,
        public bool $official = false,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,95}$/D', $this->id) !== 1) {
            throw new InvalidArgumentException('Trusted add-on key id is invalid.');
        }
        if (strlen($this->publicKeyPem) < 64 || strlen($this->publicKeyPem) > 32768) {
            throw new InvalidArgumentException('Trusted add-on public key size is invalid.');
        }
        if (openssl_pkey_get_public($this->publicKeyPem) === false) {
            throw new InvalidArgumentException('Trusted add-on public key is invalid.');
        }
    }
}
