<?php

declare(strict_types=1);

namespace Forwext\Core\Social\Interaction;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\Username;

final readonly class UserRelationshipEntry
{
    public function __construct(
        public EntityId $userId,
        public Username $username,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
