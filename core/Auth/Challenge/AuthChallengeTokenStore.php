<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Challenge;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface AuthChallengeTokenStore
{
    public function issue(
        EntityId $userId,
        AuthChallengePurpose $purpose,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): string;

    public function consume(
        string $token,
        AuthChallengePurpose $purpose,
        DateTimeImmutable $now,
    ): ?AuthChallengeGrant;
}
