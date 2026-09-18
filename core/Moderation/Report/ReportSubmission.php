<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ReportSubmission
{
    public function __construct(
        public EntityId $reportId,
        public EntityId $groupId,
        public ?EntityId $reporterUserId,
        public string $detail,
        DateTimeImmutable $createdAt,
    ) {
        if (preg_match('/^[0-9a-f]{32}$/D', $this->reportId->value()) !== 1
            || preg_match('/^[0-9a-f]{32}$/D', $this->groupId->value()) !== 1
        ) {
            throw new InvalidArgumentException('Report submission identifier is invalid.');
        }
        if ($this->reporterUserId !== null) {
            UserId::assert($this->reporterUserId);
        }
        if (strlen($this->detail) > 2000) {
            throw new InvalidArgumentException('Report submission detail exceeds 2000 UTF-8 bytes.');
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public DateTimeImmutable $createdAt;
}
