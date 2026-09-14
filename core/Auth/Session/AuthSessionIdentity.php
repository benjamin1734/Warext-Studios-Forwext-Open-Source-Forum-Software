<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Session;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class AuthSessionIdentity
{
    public function __construct(
        public EntityId $userId,
        public string $deviceId,
        public int $credentialVersion,
        public DateTimeImmutable $issuedAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1 || $credentialVersion < 1) {
            throw new \InvalidArgumentException('Authentication session identity is invalid.');
        }
    }
}
