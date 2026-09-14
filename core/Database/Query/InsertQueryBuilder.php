<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Query;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseException;

final class InsertQueryBuilder
{
    /** @var array<string, string|int|float|bool|null> */
    private array $values = [];

    public function __construct(private readonly string $table)
    {
        SqlIdentifier::quote($table);
    }

    public function value(string $column, string|int|float|bool|null $value): self
    {
        SqlIdentifier::quote($column);
        $this->values[$column] = $value;
        return $this;
    }

    /** @param array<string, string|int|float|bool|null> $values */
    public function values(array $values): self
    {
        foreach ($values as $column => $value) {
            $this->value($column, $value);
        }
        return $this;
    }

    public function compile(): CompiledQuery
    {
        if ($this->values === []) {
            throw new DatabaseException('INSERT requires at least one value.');
        }

        $columns = [];
        $placeholders = [];
        $parameters = [];
        $counter = 0;

        foreach ($this->values as $column => $value) {
            $columns[] = SqlIdentifier::quote($column);
            $name = 'i' . ++$counter;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $value;
        }

        return new CompiledQuery(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                SqlIdentifier::quote($this->table),
                implode(', ', $columns),
                implode(', ', $placeholders),
            ),
            $parameters,
        );
    }
}
