<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Remember;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class RememberAuthenticationResult
{
    public function __construct(
        public EntityId $userId,
        public string $sessionId,
        public string $replacementRememberToken,
    ) {
    }
}
