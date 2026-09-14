<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Challenge;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class AuthChallengeGrant
{
    public function __construct(
        public EntityId $userId,
        public AuthChallengePurpose $purpose,
    ) {
    }
}
