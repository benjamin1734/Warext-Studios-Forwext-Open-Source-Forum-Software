<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Report;

use InvalidArgumentException;

final readonly class AnalyticsReportResult
{
    /** @param list<string> $columns @param list<array<string,int|float|string|null>> $rows */
    public function __construct(
        public AnalyticsReportDefinition $definition,
        public array $columns,
        public array $rows,
        public int $suppressedRows,
        public int $effectivePrivacyMinCount,
    ) {
        if ($this->columns === [] || count($this->columns) > 32) throw new InvalidArgumentException('Analytics report columns are invalid.');
        foreach ($this->columns as $column) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1) throw new InvalidArgumentException('Analytics report column is invalid.');
        }
        if ($this->suppressedRows < 0 || $this->effectivePrivacyMinCount < 1) throw new InvalidArgumentException('Analytics report privacy metadata is invalid.');
        foreach ($this->rows as $row) {
            foreach ($this->columns as $column) if (!array_key_exists($column, $row)) throw new InvalidArgumentException('Analytics report row is missing a column.');
        }
    }
}
