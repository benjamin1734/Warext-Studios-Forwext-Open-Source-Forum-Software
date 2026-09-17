<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Discovery;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class DiscoveryThread
{
    public function __construct(
        public EntityId $threadId,
        public EntityId $forumNodeId,
        public ?EntityId $authorUserId,
        public string $title,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $activityAt,
        public bool $featured,
        public bool $unread,
        public int $visiblePostCount,
        public int $recentPostCount,
    ) {
        if ($this->title === '') {
            throw new InvalidArgumentException('Discovery thread title cannot be empty.');
        }
        if ($this->visiblePostCount < 0 || $this->recentPostCount < 0) {
            throw new InvalidArgumentException('Discovery post counts cannot be negative.');
        }
        if ($this->recentPostCount > $this->visiblePostCount) {
            throw new InvalidArgumentException('Recent post count cannot exceed visible post count.');
        }
    }

    public function replyCount(): int
    {
        return max(0, $this->visiblePostCount - 1);
    }
}
