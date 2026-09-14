<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Credential;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class CredentialRecord
{
    public function __construct(
        public EntityId $userId,
        public string $passwordHash,
        public int $version,
        public DateTimeImmutable $passwordChangedAt,
    ) {
        if ($passwordHash === '' || strlen($passwordHash) > 255 || $version < 1) {
            throw new \InvalidArgumentException('Credential record is invalid.');
        }
    }
}
