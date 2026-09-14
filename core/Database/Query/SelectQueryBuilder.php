<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Query;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseException;

final class SelectQueryBuilder
{
    /** @var non-empty-list<string> */
    private array $columns;

    private WhereCriteria $where;

    /** @var list<array{column: string, direction: OrderDirection}> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private LockMode $lockMode = LockMode::None;

    /** @param non-empty-list<string> $columns */
    public function __construct(
        private readonly string $table,
        array $columns = ['*'],
        private readonly ?string $alias = null,
    ) {
        SqlIdentifier::quote($table);
        if ($alias !== null) {
            SqlIdentifier::quote($alias);
        }
        if ($columns === []) {
            throw new DatabaseException('SELECT must contain at least one column.');
        }
        foreach ($columns as $column) {
            SqlIdentifier::selectable($column);
        }

        $this->columns = array_values($columns);
        $this->where = new WhereCriteria();
    }

    public function __clone()
    {
        $this->where = clone $this->where;
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

    public function orderBy(string $column, OrderDirection $direction = OrderDirection::Asc): self
    {
        SqlIdentifier::quote($column);
        $this->orders[] = ['column' => $column, 'direction' => $direction];
        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1 || $limit > 10_000) {
            throw new DatabaseException('SELECT limit must be between 1 and 10000.');
        }
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new DatabaseException('SELECT offset may not be negative.');
        }
        $this->offset = $offset;
        return $this;
    }

    public function forUpdate(): self
    {
        $this->lockMode = LockMode::ForUpdate;
        return $this;
    }

    public function forShare(): self
    {
        $this->lockMode = LockMode::Shared;
        return $this;
    }

    public function compile(): CompiledQuery
    {
        if ($this->offset !== null && $this->limit === null) {
            throw new DatabaseException('SELECT offset requires an explicit limit.');
        }

        $parameters = [];
        $counter = 0;
        $quotedColumns = array_map(
            static fn (string $column): string => SqlIdentifier::selectable($column),
            $this->columns,
        );
        $sql = sprintf(
            'SELECT %s FROM %s%s',
            implode(', ', $quotedColumns),
            SqlIdentifier::quote($this->table),
            $this->alias === null ? '' : ' AS ' . SqlIdentifier::quote($this->alias),
        );
        $sql .= $this->where->compile($parameters, $counter);

        if ($this->orders !== []) {
            $orderParts = [];
            foreach ($this->orders as $order) {
                $orderParts[] = SqlIdentifier::quote($order['column']) . ' ' . $order['direction']->value;
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        $requiresTransaction = $this->lockMode !== LockMode::None;
        $sql .= match ($this->lockMode) {
            LockMode::None => '',
            LockMode::ForUpdate => ' FOR UPDATE',
            LockMode::Shared => ' LOCK IN SHARE MODE',
        };

        return new CompiledQuery($sql, $parameters, $requiresTransaction);
    }

    public function compileCount(): CompiledQuery
    {
        $parameters = [];
        $counter = 0;
        $sql = sprintf(
            'SELECT COUNT(*) AS `aggregate` FROM %s%s',
            SqlIdentifier::quote($this->table),
            $this->alias === null ? '' : ' AS ' . SqlIdentifier::quote($this->alias),
        );
        $sql .= $this->where->compile($parameters, $counter);

        return new CompiledQuery($sql, $parameters);
    }
}
