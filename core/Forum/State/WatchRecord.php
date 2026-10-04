<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\State;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class WatchRecord
{
    public function __construct(
        public EntityId $targetId,
        public WatchNotificationMode $notificationMode,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
