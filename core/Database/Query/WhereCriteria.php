<?php

declare(strict_types=1);

namespace Forwext\Core\Database\Query;

use Forwext\Core\Database\DatabaseException;

final class WhereCriteria
{
    /**
     * @var list<array{
     *   type: 'compare'|'in'|'null',
     *   column: string,
     *   operator?: ComparisonOperator,
     *   value?: string|int|float|bool|null,
     *   values?: list<string|int|float|bool>,
     *   negate?: bool
     * }>
     */
    private array $conditions = [];

    public function compare(
        string $column,
        ComparisonOperator $operator,
        string|int|float|bool|null $value,
    ): self {
        SqlIdentifier::quote($column);

        if ($value === null && !in_array($operator, [ComparisonOperator::Equal, ComparisonOperator::NotEqual], true)) {
            throw new DatabaseException('NULL comparisons support only equality or inequality.');
        }

        $this->conditions[] = [
            'type' => 'compare',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    /** @param list<string|int|float|bool> $values */
    public function in(string $column, array $values, bool $negate = false): self
    {
        SqlIdentifier::quote($column);

        $validated = [];
        foreach ($values as $value) {
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new DatabaseException('IN values must be scalar non-null database values.');
            }
            $validated[] = $value;
        }

        $this->conditions[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $validated,
            'negate' => $negate,
        ];
        return $this;
    }

    public function isNull(string $column, bool $negate = false): self
    {
        SqlIdentifier::quote($column);
        $this->conditions[] = [
            'type' => 'null',
            'column' => $column,
            'negate' => $negate,
        ];
        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }

    /** @param array<string, string|int|float|bool|null> $parameters */
    public function compile(array &$parameters, int &$counter): string
    {
        if ($this->conditions === []) {
            return '';
        }

        $clauses = [];
        foreach ($this->conditions as $condition) {
            $column = SqlIdentifier::quote($condition['column']);

            if ($condition['type'] === 'null') {
                $clauses[] = $column . (($condition['negate'] ?? false) ? ' IS NOT NULL' : ' IS NULL');
                continue;
            }

            if ($condition['type'] === 'in') {
                $values = $condition['values'] ?? [];
                $negate = $condition['negate'] ?? false;
                if ($values === []) {
                    $clauses[] = $negate ? '1 = 1' : '0 = 1';
                    continue;
                }

                $placeholders = [];
                foreach ($values as $value) {
                    $name = 'w' . ++$counter;
                    $parameters[$name] = $value;
                    $placeholders[] = ':' . $name;
                }

                $clauses[] = sprintf(
                    '%s %sIN (%s)',
                    $column,
                    $negate ? 'NOT ' : '',
                    implode(', ', $placeholders),
                );
                continue;
            }

            $operator = $condition['operator'] ?? throw new DatabaseException('Missing comparison operator.');
            $value = $condition['value'] ?? null;
            if ($value === null) {
                $clauses[] = $column . ($operator === ComparisonOperator::Equal ? ' IS NULL' : ' IS NOT NULL');
                continue;
            }

            $name = 'w' . ++$counter;
            $parameters[$name] = $value;
            $clauses[] = sprintf('%s %s :%s', $column, $operator->value, $name);
        }

        return ' WHERE ' . implode(' AND ', $clauses);
    }
}
