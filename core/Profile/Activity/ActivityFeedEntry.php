<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class ActivityFeedEntry
{
    public function __construct(
        public ActivityFeedType $type,
        public ?EntityId $actorUserId,
        public EntityId $subjectId,
        public ?EntityId $forumNodeId,
        public ?EntityId $profileOwnerUserId,
        public ?EntityId $profilePostId,
        public DateTimeImmutable $occurredAt,
        public string $summary,
    ) {
    }
}
