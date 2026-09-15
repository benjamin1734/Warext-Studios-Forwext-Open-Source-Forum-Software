<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class ModerationAuditContext
{
    public DateTimeImmutable $occurredAt;

    public function __construct(
        public EntityId $actorUserId,
        public ModerationReasonCode $reasonCode,
        public ModerationRequestId $requestId,
        DateTimeImmutable $occurredAt,
    ) {
        UserId::assert($this->actorUserId);
        $this->occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
    }
}
