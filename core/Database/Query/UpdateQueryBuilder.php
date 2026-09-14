<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Query;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseException;

final class UpdateQueryBuilder
{
    /** @var array<string, string|int|float|bool|null> */
    private array $values = [];

    private WhereCriteria $where;
    private bool $allowAllRows = false;

    public function __construct(private readonly string $table)
    {
        SqlIdentifier::quote($table);
        $this->where = new WhereCriteria();
    }

    public function set(string $column, string|int|float|bool|null $value): self
    {
        SqlIdentifier::quote($column);
        $this->values[$column] = $value;
        return $this;
    }

    /** @param array<string, string|int|float|bool|null> $values */
    public function setMany(array $values): self
    {
        foreach ($values as $column => $value) {
            $this->set($column, $value);
        }
        return $this;
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
        if ($this->values === []) {
            throw new DatabaseException('UPDATE requires at least one SET value.');
        }
        if ($this->where->isEmpty() && !$this->allowAllRows) {
            throw new DatabaseException('UPDATE without WHERE requires explicit allowAllRows().');
        }

        $parameters = [];
        $setCounter = 0;
        $sets = [];

        foreach ($this->values as $column => $value) {
            $name = 's' . ++$setCounter;
            $sets[] = SqlIdentifier::quote($column) . ' = :' . $name;
            $parameters[$name] = $value;
        }

        $whereCounter = 0;
        $sql = sprintf(
            'UPDATE %s SET %s',
            SqlIdentifier::quote($this->table),
            implode(', ', $sets),
        );
        $sql .= $this->where->compile($parameters, $whereCounter);

        return new CompiledQuery($sql, $parameters);
    }
}
