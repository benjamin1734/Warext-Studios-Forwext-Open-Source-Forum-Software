<?php

declare(strict_types=1);

namespace Forwext\Core\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CronExpression
{
    private function __construct(
        private CronField $minute,
        private CronField $hour,
        private CronField $dayOfMonth,
        private CronField $month,
        private CronField $dayOfWeek,
        private string $expression,
    ) {
    }

    public static function parse(string $expression): self
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (!is_array($parts) || count($parts) !== 5) {
            throw new InvalidArgumentException('Cron expression must contain exactly five fields.');
        }

        return new self(
            CronField::parse($parts[0], 0, 59),
            CronField::parse($parts[1], 0, 23),
            CronField::parse($parts[2], 1, 31),
            CronField::parse($parts[3], 1, 12),
            CronField::parse($parts[4], 0, 6, dayOfWeek: true),
            implode(' ', $parts),
        );
    }

    public function isDue(DateTimeImmutable $time): bool
    {
        $utc = $time->setTimezone(new DateTimeZone('UTC'));
        if (
            !$this->minute->matches((int) $utc->format('i'))
            || !$this->hour->matches((int) $utc->format('G'))
            || !$this->month->matches((int) $utc->format('n'))
        ) {
            return false;
        }

        $dayOfMonthMatches = $this->dayOfMonth->matches((int) $utc->format('j'));
        $dayOfWeekMatches = $this->dayOfWeek->matches((int) $utc->format('w'));

        if ($this->dayOfMonth->isWildcard() && $this->dayOfWeek->isWildcard()) {
            return true;
        }
        if ($this->dayOfMonth->isWildcard()) {
            return $dayOfWeekMatches;
        }
        if ($this->dayOfWeek->isWildcard()) {
            return $dayOfMonthMatches;
        }

        return $dayOfMonthMatches || $dayOfWeekMatches;
    }

    public function nextRunAfter(DateTimeImmutable $time, int $maxYears = 5): ?DateTimeImmutable
    {
        if ($maxYears < 1 || $maxYears > 10) {
            throw new InvalidArgumentException('Cron next-run horizon must be between one and ten years.');
        }

        $utc = $time->setTimezone(new DateTimeZone('UTC'));
        $start = $utc->setTime((int) $utc->format('G'), (int) $utc->format('i'), 0)->modify('+1 minute');
        $limit = $start->modify('+' . $maxYears . ' years');
        $day = $start->setTime(0, 0, 0);
        $hours = $this->hour->values();
        $minutes = $this->minute->values();

        while ($day <= $limit) {
            if (
                $this->month->matches((int) $day->format('n'))
                && $this->dayMatches($day)
            ) {
                foreach ($hours as $hour) {
                    foreach ($minutes as $minute) {
                        $candidate = $day->setTime($hour, $minute, 0);
                        if ($candidate >= $start && $candidate <= $limit) {
                            return $candidate;
                        }
                    }
                }
            }
            $day = $day->modify('+1 day');
        }

        return null;
    }

    private function dayMatches(DateTimeImmutable $utc): bool
    {
        $dayOfMonthMatches = $this->dayOfMonth->matches((int) $utc->format('j'));
        $dayOfWeekMatches = $this->dayOfWeek->matches((int) $utc->format('w'));

        if ($this->dayOfMonth->isWildcard() && $this->dayOfWeek->isWildcard()) {
            return true;
        }
        if ($this->dayOfMonth->isWildcard()) {
            return $dayOfWeekMatches;
        }
        if ($this->dayOfWeek->isWildcard()) {
            return $dayOfMonthMatches;
        }

        return $dayOfMonthMatches || $dayOfWeekMatches;
    }

    public function value(): string
    {
        return $this->expression;
    }
}
