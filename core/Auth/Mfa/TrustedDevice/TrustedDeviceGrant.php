<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\TrustedDevice;

use DateTimeImmutable;

final readonly class TrustedDeviceGrant
{
    public function __construct(public string $token, public DateTimeImmutable $expiresAt)
    {
    }
}
