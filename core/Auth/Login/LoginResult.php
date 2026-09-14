<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Login;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class LoginResult
{
    public function __construct(
        public EntityId $userId,
        public string $sessionId,
        public string $deviceId,
        public ?string $rememberToken,
    ) {
    }
}
