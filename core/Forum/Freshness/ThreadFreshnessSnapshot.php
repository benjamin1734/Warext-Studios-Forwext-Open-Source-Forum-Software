<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Freshness;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class ThreadFreshnessSnapshot
{
    public function __construct(
        public EntityId $threadId,
        public EntityId $forumNodeId,
        public ?EntityId $authorUserId,
        public string $title,
        public DateTimeImmutable $lastActivityAt,
        public int $ageDays,
        public bool $stale,
        public bool $archived,
        public bool $locked,
        public bool $featured,
        public ?DateTimeImmutable $lastRenewedAt,
        public int $renewCount,
        public ?DateTimeImmutable $notifiedAt,
        public ?DateTimeImmutable $reviewRequestedAt,
        public ?DateTimeImmutable $autoLockedAt,
        public ?DateTimeImmutable $autoArchivedAt,
        public ?DateTimeImmutable $autoUnfeaturedAt,
    ) {
    }

    public function badge(): ?string
    {
        if ($this->archived) {
            return 'Arşivlenmiş';
        }
        return $this->stale ? 'Güncelliğini yitirmiş' : null;
    }
}
