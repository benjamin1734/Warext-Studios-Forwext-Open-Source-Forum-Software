<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Device;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class DeviceRecord
{
    public function __construct(
        public string $deviceId,
        public EntityId $userId,
        public string $userAgentFingerprint,
        public string $lastIpFingerprint,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
        public ?DateTimeImmutable $revokedAt = null,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $userAgentFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $lastIpFingerprint) !== 1
        ) {
            throw new \InvalidArgumentException('Authentication device record is invalid.');
        }
    }
}
