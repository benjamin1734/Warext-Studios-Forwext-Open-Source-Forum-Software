<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Passkey;

final readonly class VerifiedPasskeyCredential
{
    public function __construct(
        public string $credentialId,
        public string $credentialRecordJson,
    ) {
        if ($credentialId === '' || $credentialRecordJson === '') {
            throw new \InvalidArgumentException('Verified passkey credential is invalid.');
        }
    }
}
