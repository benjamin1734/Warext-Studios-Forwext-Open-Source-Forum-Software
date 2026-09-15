<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class PostHistoryEntry
{
    public function __construct(
        public int $snapshotVersion,
        public PostBody $body,
        public PostModerationState $moderationState,
        public bool $deleted,
        public string $action,
        public ?EntityId $actorUserId,
        public DateTimeImmutable $changedAt,
    ) {
    }
}
