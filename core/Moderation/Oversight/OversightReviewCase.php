<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class OversightReviewCase
{
    public DateTimeImmutable $openedAt;
    public ?DateTimeImmutable $resolvedAt;

    public function __construct(
        public EntityId $caseId,
        public EntityId $sourceAuditId,
        public EntityId $openedByUserId,
        public string $summary,
        public OversightReviewStatus $status,
        DateTimeImmutable $openedAt,
        ?DateTimeImmutable $resolvedAt = null,
        public ?EntityId $resolvedByUserId = null,
        public ?string $resolution = null,
    ) {
        UserId::assert($this->openedByUserId);
        if ($this->resolvedByUserId !== null) UserId::assert($this->resolvedByUserId);
        if (trim($this->summary) === '' || strlen($this->summary) > 1000) {
            throw new InvalidArgumentException('Oversight review summary must contain 1-1000 bytes.');
        }
        if ($this->resolution !== null && (trim($this->resolution) === '' || strlen($this->resolution) > 1000)) {
            throw new InvalidArgumentException('Oversight review resolution must contain 1-1000 bytes.');
        }
        $utc = new DateTimeZone('UTC');
        $this->openedAt = $openedAt->setTimezone($utc);
        $this->resolvedAt = $resolvedAt?->setTimezone($utc);
        if ($this->status === OversightReviewStatus::Resolved
            && ($this->resolvedAt === null || $this->resolvedByUserId === null || $this->resolution === null)
        ) {
            throw new InvalidArgumentException('Resolved oversight review requires resolution metadata.');
        }
        if ($this->status === OversightReviewStatus::Open
            && ($this->resolvedAt !== null || $this->resolvedByUserId !== null || $this->resolution !== null)
        ) {
            throw new InvalidArgumentException('Open oversight review cannot contain resolution metadata.');
        }
    }
}
