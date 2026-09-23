<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class AnalyticsSavedReport
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $reportId,
        public EntityId $ownerUserId,
        public string $name,
        public AnalyticsReportDefinition $definition,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($this->ownerUserId);
        $name = trim($this->name);
        if ($name === '' || strlen($name) > 120 || $name !== $this->name) throw new InvalidArgumentException('Analytics saved report name is invalid.');
        $utc = new DateTimeZone('UTC');
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);
        if ($this->updatedAt < $this->createdAt) throw new InvalidArgumentException('Analytics saved report timestamps are invalid.');
    }

    public static function generateId(): EntityId { return EntityId::fromString(bin2hex(random_bytes(16))); }
}
