<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Device;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface DeviceRepository
{
    public function touch(
        EntityId $userId,
        ?string $presentedDeviceId,
        string $userAgentFingerprint,
        string $ipFingerprint,
        DateTimeImmutable $now,
    ): DeviceRecord;

    public function revoke(EntityId $userId, string $deviceId, DateTimeImmutable $now): bool;
}
