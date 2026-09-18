<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ReportGroup
{
    public function __construct(
        public EntityId $groupId,
        public string $targetType,
        public EntityId $targetId,
        public string $targetTitle,
        public string $reasonKey,
        public string $reasonLabel,
        public ReportStatus $status,
        public ?EntityId $assignedModeratorUserId,
        public int $reportCount,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        DateTimeImmutable $latestReportAt,
    ) {
        if (preg_match('/^[0-9a-f]{32}$/D', $this->groupId->value()) !== 1) {
            throw new InvalidArgumentException('Report group id must be a 32-character lowercase hex id.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $this->targetType) !== 1) {
            throw new InvalidArgumentException('Report group target type is invalid.');
        }
        if (trim($this->targetTitle) === '' || strlen($this->targetTitle) > 255) {
            throw new InvalidArgumentException('Report group target title is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->reasonKey) !== 1
            || trim($this->reasonLabel) === '' || strlen($this->reasonLabel) > 100
        ) {
            throw new InvalidArgumentException('Report group reason snapshot is invalid.');
        }
        if ($this->assignedModeratorUserId !== null) {
            UserId::assert($this->assignedModeratorUserId);
        }
        if ($this->reportCount < 1 || $this->reportCount > 4294967295) {
            throw new InvalidArgumentException('Report group count is invalid.');
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
        $this->updatedAt = $updatedAt->setTimezone(new DateTimeZone('UTC'));
        $this->latestReportAt = $latestReportAt->setTimezone(new DateTimeZone('UTC'));
    }

    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
    public DateTimeImmutable $latestReportAt;
}
