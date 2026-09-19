<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ReferralRegistrationAttribution
{
    public function attributeRegistration(
        EntityId $referredUserId,
        ?string $code,
        string $ipFingerprint,
        ?string $deviceFingerprint,
        DateTimeImmutable $at,
    ): void;
}
