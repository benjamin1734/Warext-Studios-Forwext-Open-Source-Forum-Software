<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class ConnectedAccount
{
    public function __construct(
        public EntityId $userId,
        public string $providerId,
        public string $subject,
        public ?string $emailNormalized,
        public ?string $displayName,
        public DateTimeImmutable $linkedAt,
        public DateTimeImmutable $lastAuthenticatedAt,
    ) {
    }
}
