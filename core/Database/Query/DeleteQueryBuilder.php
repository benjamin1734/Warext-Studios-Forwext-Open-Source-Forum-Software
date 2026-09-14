<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Query;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseException;

final class DeleteQueryBuilder
{
    private WhereCriteria $where;
    private bool $allowAllRows = false;

    public function __construct(private readonly string $table)
    {
        SqlIdentifier::quote($table);
        $this->where = new WhereCriteria();
    }

    public function where(
        string $column,
        ComparisonOperator $operator,
        string|int|float|bool|null $value,
    ): self {
        $this->where->compare($column, $operator, $value);
        return $this;
    }

    public function whereEquals(string $column, string|int|float|bool|null $value): self
    {
        return $this->where($column, ComparisonOperator::Equal, $value);
    }

    /** @param list<string|int|float|bool> $values */
    public function whereIn(string $column, array $values, bool $negate = false): self
    {
        $this->where->in($column, $values, $negate);
        return $this;
    }

    public function whereNull(string $column, bool $negate = false): self
    {
        $this->where->isNull($column, $negate);
        return $this;
    }

    public function allowAllRows(): self
    {
        $this->allowAllRows = true;
        return $this;
    }

    public function compile(): CompiledQuery
    {
        if ($this->where->isEmpty() && !$this->allowAllRows) {
            throw new DatabaseException('DELETE without WHERE requires explicit allowAllRows().');
        }

        $parameters = [];
        $counter = 0;
        $sql = 'DELETE FROM ' . SqlIdentifier::quote($this->table);
        $sql .= $this->where->compile($parameters, $counter);

        return new CompiledQuery($sql, $parameters);
    }
}
