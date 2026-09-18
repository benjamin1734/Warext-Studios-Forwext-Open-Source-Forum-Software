<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class BugAuditEntry
{
    public DateTimeImmutable $occurredAt;

    public function __construct(
        public EntityId $auditId,
        public EntityId $actorUserId,
        public string $action,
        public string $targetType,
        public string $targetId,
        public string $requestId,
        DateTimeImmutable $occurredAt,
    ) {
        UserId::assert($this->actorUserId);
        if (preg_match('/^[a-z][a-z0-9._-]{1,95}$/D', $this->action) !== 1
            || preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $this->targetType) !== 1
            || $this->targetId === ''
            || strlen($this->targetId) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $this->targetId) !== 1
            || $this->requestId === ''
            || strlen($this->requestId) > 100
        ) {
            throw new InvalidArgumentException('Bug audit entry is invalid.');
        }
        $this->occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
    }
}
