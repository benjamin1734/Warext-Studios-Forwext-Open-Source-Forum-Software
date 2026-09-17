<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class ProfilePost
{
    public function __construct(
        public EntityId $id,
        public EntityId $profileOwnerUserId,
        public ?EntityId $authorUserId,
        public ProfileActivityBody $body,
        public ProfileActivityModerationState $moderationState,
        public ?DateTimeImmutable $deletedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        ProfilePostId::assert($this->id);
    }

    public function isDeleted(): bool { return $this->deletedAt !== null; }
}
