<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class BugReport
{
    public ?DateTimeImmutable $finalizedAt;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $reportId,
        public string $categoryKey,
        public ?EntityId $reporterUserId,
        public ?EntityId $assignedUserId,
        public string $title,
        public string $summary,
        public BugReportSeverity $severity,
        public BugReportStatus $status,
        ?DateTimeImmutable $finalizedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        public int $version,
    ) {
        BugReportCategory::assertKey($this->categoryKey);
        if ($this->reporterUserId !== null) {
            UserId::assert($this->reporterUserId);
        }
        if ($this->assignedUserId !== null) {
            UserId::assert($this->assignedUserId);
        }
        if (trim($this->title) === '' || strlen($this->title) > 200) {
            throw new InvalidArgumentException('Bug report title must contain 1-200 UTF-8 bytes.');
        }
        if (trim($this->summary) === '' || strlen($this->summary) > 5000) {
            throw new InvalidArgumentException('Bug report summary must contain 1-5000 UTF-8 bytes.');
        }
        if ($this->version < 1) {
            throw new InvalidArgumentException('Bug report version must be positive.');
        }

        $utc = new DateTimeZone('UTC');
        $this->finalizedAt = $finalizedAt?->setTimezone($utc);
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);

        if ($this->status->isTerminal() && $this->finalizedAt === null) {
            throw new InvalidArgumentException('Terminal bug reports require a finalized timestamp.');
        }
        if (!$this->status->isTerminal() && $this->finalizedAt !== null) {
            throw new InvalidArgumentException('Active bug reports cannot contain a finalized timestamp.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function isReporter(EntityId $userId): bool
    {
        return $this->reporterUserId?->equals($userId) ?? false;
    }
}
