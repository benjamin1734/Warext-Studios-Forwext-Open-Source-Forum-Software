<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Freshness;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class ThreadFreshnessReview
{
    public function __construct(
        public EntityId $threadId,
        public EntityId $forumNodeId,
        public ?EntityId $authorUserId,
        public string $title,
        public int $ageDays,
        public DateTimeImmutable $requestedAt,
        public string $status = 'pending',
        public ?EntityId $resolvedByUserId = null,
        public ?DateTimeImmutable $resolvedAt = null,
        public ?string $resolution = null,
    ) {
    }
}
