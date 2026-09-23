<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Analytics\Report\AnalyticsReportDataset;
use Forwext\Core\Analytics\Report\AnalyticsReportDefinition;
use InvalidArgumentException;

final class AnalyticsReportInputReader
{
    /** @param array<string,mixed> $input */
    public static function read(array $input, ?AnalyticsReportDataset $fallbackDataset = null): AnalyticsReportDefinition
    {
        $rawDataset = $input['dataset'] ?? null;
        $dataset = $rawDataset === null || $rawDataset === ''
            ? $fallbackDataset
            : (is_string($rawDataset) ? AnalyticsReportDataset::tryFrom($rawDataset) : null);
        if (!$dataset instanceof AnalyticsReportDataset) {
            throw new InvalidArgumentException('Analytics report dataset is invalid.');
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $from = self::date($input['from'] ?? $today->modify('-29 days')->format('Y-m-d'));
        $to = self::date($input['to'] ?? $today->format('Y-m-d'));

        $privacy = $input['privacy_min_count'] ?? 5;
        if (is_string($privacy) && preg_match('/^\d{1,3}$/D', $privacy) === 1) {
            $privacy = (int) $privacy;
        }
        if (!is_int($privacy)) {
            throw new InvalidArgumentException('Analytics privacy aggregation threshold is invalid.');
        }

        $filters = [];
        foreach ($dataset->allowedFilters() as $key) {
            $raw = $input['filter_'.$key] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            if (!is_string($raw)) {
                throw new InvalidArgumentException('Analytics report filter is invalid.');
            }
            $filters[$key] = $raw;
        }

        return new AnalyticsReportDefinition($dataset, $from, $to, $filters, $privacy);
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Analytics report date is invalid.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Analytics report date is invalid.');
        }
        return $date;
    }
}
