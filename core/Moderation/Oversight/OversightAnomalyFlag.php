<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class OversightAnomalyFlag
{
    public DateTimeImmutable $createdAt;
    public ?DateTimeImmutable $resolvedAt;

    public function __construct(
        public EntityId $flagId,
        public EntityId $sourceAuditId,
        public string $flagType,
        public OversightAnomalySeverity $severity,
        public string $details,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $resolvedAt = null,
        public ?EntityId $resolvedByUserId = null,
        public ?string $resolution = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->flagType) !== 1) {
            throw new InvalidArgumentException('Oversight anomaly flag type is invalid.');
        }
        if (trim($this->details) === '' || strlen($this->details) > 1000) {
            throw new InvalidArgumentException('Oversight anomaly details must contain 1-1000 bytes.');
        }
        if ($this->resolvedByUserId !== null) UserId::assert($this->resolvedByUserId);
        $utc = new DateTimeZone('UTC');
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->resolvedAt = $resolvedAt?->setTimezone($utc);
        if ($this->resolvedAt !== null && ($this->resolvedByUserId === null || $this->resolution === null)) {
            throw new InvalidArgumentException('Resolved oversight flag requires resolution metadata.');
        }
        if ($this->resolvedAt === null && ($this->resolvedByUserId !== null || $this->resolution !== null)) {
            throw new InvalidArgumentException('Open oversight flag cannot contain resolution metadata.');
        }
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }
}
