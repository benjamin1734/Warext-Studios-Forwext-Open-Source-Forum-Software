<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Remember;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class RememberTokenGrant
{
    public function __construct(
        public EntityId $userId,
        public string $deviceId,
        public int $credentialVersion,
        public string $replacementToken,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1 || $credentialVersion < 1) {
            throw new \InvalidArgumentException('Remember-token grant is invalid.');
        }
    }
}
