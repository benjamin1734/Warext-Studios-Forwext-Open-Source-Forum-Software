<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Query;

final class QueryBuilder
{
    /** @param non-empty-list<string> $columns */
    public function select(string $table, array $columns = ['*'], ?string $alias = null): SelectQueryBuilder
    {
        return new SelectQueryBuilder($table, $columns, $alias);
    }

    public function insert(string $table): InsertQueryBuilder
    {
        return new InsertQueryBuilder($table);
    }

    public function update(string $table): UpdateQueryBuilder
    {
        return new UpdateQueryBuilder($table);
    }

    public function delete(string $table): DeleteQueryBuilder
    {
        return new DeleteQueryBuilder($table);
    }
}
