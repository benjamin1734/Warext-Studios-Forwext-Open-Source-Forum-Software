<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Report;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class AnalyticsReportDefinition
{
    public DateTimeImmutable $from;
    public DateTimeImmutable $to;

    /** @var array<string,string> */
    public array $filters;

    /** @param array<string,string> $filters */
    public function __construct(
        public AnalyticsReportDataset $dataset,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $filters = [],
        public int $privacyMinCount = 5,
    ) {
        $utc = new DateTimeZone('UTC');
        $this->from = $from->setTimezone($utc)->setTime(0, 0);
        $this->to = $to->setTimezone($utc)->setTime(0, 0);
        if ($this->to < $this->from) throw new InvalidArgumentException('Analytics report date range is reversed.');
        $exclusiveEnd = $this->to->add(new DateInterval('P1D'));
        $days = (int) $this->from->diff($exclusiveEnd)->format('%a');
        if ($days < 1 || $days > 366) throw new InvalidArgumentException('Analytics report date range must be 1..366 days.');
        if ($this->privacyMinCount < 1 || $this->privacyMinCount > 100) throw new InvalidArgumentException('Analytics privacy aggregation threshold must be 1..100.');
        $allowed = array_fill_keys($this->dataset->allowedFilters(), true);
        $normalized = [];
        foreach ($filters as $key => $value) {
            if (!is_string($key) || !isset($allowed[$key])) throw new InvalidArgumentException('Analytics report filter is not allowed for this dataset.');
            $value = trim($value);
            if ($value === '' || strlen($value) > 96 || preg_match('/^[A-Za-z0-9._:-]+$/D', $value) !== 1) throw new InvalidArgumentException('Analytics report filter value is invalid.');
            $normalized[$key] = $key === 'currency' ? strtoupper($value) : strtolower($value);
        }
        ksort($normalized, SORT_STRING);
        $this->filters = $normalized;
    }

    public function exclusiveEnd(): DateTimeImmutable { return $this->to->add(new DateInterval('P1D')); }
    public function withPrivacyMinCount(int $privacyMinCount): self { return new self($this->dataset, $this->from, $this->to, $this->filters, $privacyMinCount); }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['dataset'=>$this->dataset->value,'from'=>$this->from->format('Y-m-d'),'to'=>$this->to->format('Y-m-d'),'filters'=>$this->filters,'privacy_min_count'=>$this->privacyMinCount]; }
}
