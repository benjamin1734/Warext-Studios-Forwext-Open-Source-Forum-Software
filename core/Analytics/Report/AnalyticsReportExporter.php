<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Report;

use RuntimeException;

final class AnalyticsReportExporter
{
    public static function csv(AnalyticsReportResult $result): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Analytics CSV buffer could not be opened.');
        }

        try {
            if (fputcsv($stream, $result->columns, ',', '"', '\\') === false) {
                throw new RuntimeException('Analytics CSV header could not be encoded.');
            }

            foreach ($result->rows as $row) {
                $values = [];
                foreach ($result->columns as $column) {
                    $values[] = self::csvValue($row[$column] ?? null);
                }
                if (fputcsv($stream, $values, ',', '"', '\\') === false) {
                    throw new RuntimeException('Analytics CSV row could not be encoded.');
                }
            }

            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new RuntimeException('Analytics CSV buffer could not be read.');
            }
            return $csv;
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string,mixed> */
    public static function jsonPayload(AnalyticsReportResult $result): array
    {
        return [
            'definition'=>$result->definition->toArray(),
            'columns'=>$result->columns,
            'rows'=>$result->rows,
            'suppressed_rows'=>$result->suppressedRows,
            'effective_privacy_min_count'=>$result->effectivePrivacyMinCount,
        ];
    }

    private static function csvValue(int|float|string|null $value): int|float|string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (preg_match('/^(?:[\x09\x0D]|[ ]*[=+\-@])/D', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
