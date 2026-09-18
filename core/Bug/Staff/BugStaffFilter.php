<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class BugStaffFilter
{
    public function __construct(
        public ?string $text = null,
        public ?BugReportStatus $status = null,
        public ?BugReportSeverity $severity = null,
        public ?string $categoryKey = null,
        public ?EntityId $assignedUserId = null,
        public bool $unassignedOnly = false,
        public int $limit = 50,
        public int $offset = 0,
    ) {
        if ($this->text !== null && (trim($this->text) === '' || strlen($this->text) > 200)) {
            throw new InvalidArgumentException('Bug staff search text must contain 1-200 UTF-8 bytes.');
        }
        if ($this->categoryKey !== null
            && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->categoryKey) !== 1
        ) {
            throw new InvalidArgumentException('Bug staff category filter is invalid.');
        }
        if ($this->assignedUserId !== null) {
            UserId::assert($this->assignedUserId);
        }
        if ($this->assignedUserId !== null && $this->unassignedOnly) {
            throw new InvalidArgumentException('Bug staff assignee filters conflict.');
        }
        if ($this->limit < 1 || $this->limit > 1000 || $this->offset < 0 || $this->offset > 100000) {
            throw new InvalidArgumentException('Bug staff pagination is invalid.');
        }
    }

    public function withLimit(int $limit): self
    {
        return new self(
            $this->text,
            $this->status,
            $this->severity,
            $this->categoryKey,
            $this->assignedUserId,
            $this->unassignedOnly,
            $limit,
            $this->offset,
        );
    }
}
