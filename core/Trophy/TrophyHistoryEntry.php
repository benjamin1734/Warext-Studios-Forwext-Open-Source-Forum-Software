<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class TrophyHistoryEntry
{
    public DateTimeImmutable $occurredAt;

    public function __construct(
        public int $historyId,
        public EntityId $grantId,
        public EntityId $trophyId,
        public EntityId $userId,
        public TrophyHistoryAction $action,
        public string $source,
        public ?EntityId $actorUserId,
        public ?string $reason,
        DateTimeImmutable $occurredAt,
    ) {
        if ($this->historyId < 0) throw new InvalidArgumentException('Trophy history id is invalid.');
        UserId::assert($this->userId);
        if ($this->actorUserId !== null) UserId::assert($this->actorUserId);
        $this->occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
    }
}
