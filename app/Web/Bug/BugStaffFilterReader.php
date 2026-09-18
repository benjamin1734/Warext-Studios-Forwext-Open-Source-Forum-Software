<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Bug\Staff\BugStaffFilter;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use InvalidArgumentException;
use ValueError;

final readonly class BugStaffFilterReader
{
    public function __construct(private UserRepository $users)
    {
    }

    /** @param array<string,mixed> $query */
    public function read(array $query, int $limit = 50): BugStaffFilterRequest
    {
        $text = $this->optional($query, 'q', 200);
        $category = $this->optional($query, 'category', 64);
        if ($category !== null) {
            $category = strtolower($category);
        }

        $status = null;
        $statusValue = $this->optional($query, 'status', 16);
        if ($statusValue !== null) {
            try {
                $status = BugReportStatus::from($statusValue);
            } catch (ValueError $exception) {
                throw new InvalidArgumentException('Bug staff status filter is invalid.', previous: $exception);
            }
        }

        $severity = null;
        $severityValue = $this->optional($query, 'severity', 16);
        if ($severityValue !== null) {
            try {
                $severity = BugReportSeverity::from($severityValue);
            } catch (ValueError $exception) {
                throw new InvalidArgumentException('Bug staff severity filter is invalid.', previous: $exception);
            }
        }

        $assigneeQuery = $this->optional($query, 'assignee', 80);
        $assignee = null;
        $unassigned = false;
        if ($assigneeQuery !== null) {
            if (strtolower($assigneeQuery) === 'unassigned') {
                $unassigned = true;
            } else {
                $user = $this->users->findByUsername(Username::fromString($assigneeQuery));
                if ($user === null) {
                    throw new InvalidArgumentException('Bug staff assignee filter user was not found.');
                }
                $assignee = $user->id();
                $assigneeQuery = $user->username()->display();
            }
        }

        $page = $this->positiveInt($query['page'] ?? null, 1, 2001, 1);
        $offset = ($page - 1) * $limit;

        return new BugStaffFilterRequest(
            new BugStaffFilter(
                $text,
                $status,
                $severity,
                $category,
                $assignee,
                $unassigned,
                $limit,
                $offset,
            ),
            $assigneeQuery,
        );
    }

    /** @param array<string,mixed> $query */
    private function optional(array $query, string $key, int $maxBytes): ?string
    {
        $value = $query[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Bug staff filter is invalid: ' . $key);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes) {
            throw new InvalidArgumentException('Bug staff filter is invalid: ' . $key);
        }
        return $value;
    }

    private function positiveInt(mixed $value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($parsed) || $parsed < $min || $parsed > $max) {
            throw new InvalidArgumentException('Bug staff pagination is invalid.');
        }
        return $parsed;
    }
}
