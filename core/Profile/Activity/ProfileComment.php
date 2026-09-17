<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class ProfileComment
{
    public function __construct(
        public EntityId $id,
        public EntityId $profilePostId,
        public ?EntityId $authorUserId,
        public ProfileActivityBody $body,
        public ProfileActivityModerationState $moderationState,
        public ?DateTimeImmutable $deletedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        ProfileCommentId::assert($this->id);
        ProfilePostId::assert($this->profilePostId);
    }

    public function isDeleted(): bool { return $this->deletedAt !== null; }
}
