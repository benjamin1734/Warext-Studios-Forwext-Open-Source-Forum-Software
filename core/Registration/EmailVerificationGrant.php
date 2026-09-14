<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserStatus;

final readonly class EmailVerificationGrant
{
    public function __construct(
        public EntityId $userId,
        public UserStatus $targetStatus,
    ) {
    }
}
