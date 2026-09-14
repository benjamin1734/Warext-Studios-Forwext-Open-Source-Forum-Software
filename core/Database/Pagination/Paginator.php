<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Pagination;

use Forwext\Core\Database\DatabaseException;
use Forwext\Core\Database\Query\SelectQueryBuilder;
use Forwext\Core\Database\QueryExecutor;

final readonly class Paginator
{
    public function __construct(private QueryExecutor $executor)
    {
    }

    /** @return Page<array<string, mixed>> */
    public function paginate(SelectQueryBuilder $query, PageRequest $request): Page
    {
        $count = $this->executor->fetchValue($query->compileCount());
        if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
            throw new DatabaseException('Pagination count query returned a non-integer value.');
        }

        $total = (int) $count;
        $lastPage = $total === 0
            ? 1
            : intdiv($total, $request->perPage) + (($total % $request->perPage) === 0 ? 0 : 1);
        $paged = clone $query;
        $paged->limit($request->perPage)->offset($request->offset());
        $items = $this->executor->fetchAll($paged->compile());

        return new Page($items, $total, $request->page, $request->perPage, $lastPage);
    }
}
