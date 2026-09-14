<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Pagination;

/** @template T */
final readonly class Page
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public int $lastPage,
    ) {
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->lastPage;
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }
}
