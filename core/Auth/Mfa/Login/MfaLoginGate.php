<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Login;

use Forwext\Core\Domain\Entity\EntityId;

interface MfaLoginGate
{
    public function enforce(
        EntityId $userId,
        string $deviceId,
        int $credentialVersion,
        bool $rememberRequested,
        ?string $trustedDeviceToken,
        string $identityFingerprint,
        string $ipFingerprint,
        string $deviceFingerprint,
    ): void;
}
