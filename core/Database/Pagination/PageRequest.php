<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Pagination;

use Forwext\Core\Database\DatabaseException;

final readonly class PageRequest
{
    public function __construct(
        public int $page = 1,
        public int $perPage = 20,
        int $maximumPerPage = 100,
    ) {
        if ($page < 1) {
            throw new DatabaseException('Page number must be at least 1.');
        }
        if ($maximumPerPage < 1 || $maximumPerPage > 10_000) {
            throw new DatabaseException('Maximum page size is out of range.');
        }
        if ($perPage < 1 || $perPage > $maximumPerPage) {
            throw new DatabaseException(sprintf('Page size must be between 1 and %d.', $maximumPerPage));
        }
    }

    public function offset(): int
    {
        $offset = ($this->page - 1) * $this->perPage;
        if (!is_int($offset) || $offset < 0) {
            throw new DatabaseException('Pagination offset overflowed integer range.');
        }
        return $offset;
    }
}
