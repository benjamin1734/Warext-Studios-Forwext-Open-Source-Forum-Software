<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class BugDuplicateLink
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $duplicateReportId,
        public EntityId $canonicalReportId,
        public ?EntityId $createdByUserId,
        DateTimeImmutable $createdAt,
    ) {
        if ($this->createdByUserId !== null) {
            UserId::assert($this->createdByUserId);
        }
        if ($this->duplicateReportId->equals($this->canonicalReportId)) {
            throw new InvalidArgumentException('A bug report cannot duplicate itself.');
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }
}
