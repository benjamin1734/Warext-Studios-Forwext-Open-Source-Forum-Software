<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class SupportEscalationState
{
    public DateTimeImmutable $escalatedAt;

    public function __construct(
        public EntityId $ticketId,
        public int $level,
        public ?EntityId $escalatedByUserId,
        DateTimeImmutable $escalatedAt,
    ) {
        if ($this->level < 1 || $this->level > 5) {
            throw new InvalidArgumentException('Support escalation level must be 1-5.');
        }
        if ($this->escalatedByUserId !== null) {
            UserId::assert($this->escalatedByUserId);
        }
        $this->escalatedAt = $escalatedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
