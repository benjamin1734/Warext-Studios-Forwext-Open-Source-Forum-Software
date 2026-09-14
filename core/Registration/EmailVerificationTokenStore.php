<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserStatus;

interface EmailVerificationTokenStore
{
    public function issue(
        EntityId $userId,
        UserStatus $targetStatus,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): string;

    public function consume(string $token, DateTimeImmutable $now): ?EmailVerificationGrant;
}
