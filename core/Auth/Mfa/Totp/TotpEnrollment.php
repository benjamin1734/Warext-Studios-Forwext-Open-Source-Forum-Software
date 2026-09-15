<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Totp;

final readonly class TotpEnrollment
{
    public function __construct(
        public string $secretBase32,
        public string $otpauthUri,
    ) {
    }
}
