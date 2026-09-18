<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ReportSubmissionSummary
{
    public function __construct(
        public EntityId $reportId,
        public EntityId $groupId,
        public string $targetType,
        public EntityId $targetId,
        public string $targetTitle,
        public string $reasonLabel,
        public ReportStatus $status,
        DateTimeImmutable $createdAt,
    ) {
        foreach ([$this->reportId, $this->groupId] as $id) {
            if (preg_match('/^[0-9a-f]{32}$/D', $id->value()) !== 1) {
                throw new InvalidArgumentException('Stored report identifier is invalid.');
            }
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public DateTimeImmutable $createdAt;
}
