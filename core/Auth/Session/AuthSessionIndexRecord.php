<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Session;

use DateTimeImmutable;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class AuthSessionIndexRecord
{
    public function __construct(
        public string $sessionHash,
        public EntityId $userId,
        public string $deviceId,
        public int $credentialVersion,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $lastSeenAt,
        public ?DateTimeImmutable $revokedAt = null,
    ) {
        UserId::assert($userId);
        if (
            preg_match('/^[a-f0-9]{64}$/D', $sessionHash) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1
            || $credentialVersion < 1
        ) {
            throw new AuthException('Authentication session index record is invalid.');
        }
        if ($expiresAt <= $issuedAt || $lastSeenAt < $issuedAt) {
            throw new AuthException('Authentication session index timestamps are invalid.');
        }
    }

    public function activeAt(DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && $this->expiresAt > $now;
    }
}
