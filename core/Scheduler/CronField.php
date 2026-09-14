<?php

declare(strict_types=1);

namespace Forwext\Core\Scheduler;

use InvalidArgumentException;

final readonly class CronField
{
    /** @var array<int, true> */
    private array $values;

    private function __construct(
        private bool $wildcard,
        array $values,
        private bool $dayOfWeek = false,
    ) {
        $this->values = $values;
    }

    public static function parse(string $expression, int $minimum, int $maximum, bool $dayOfWeek = false): self
    {
        $expression = trim($expression);
        if ($expression === '') {
            throw new InvalidArgumentException('Cron field cannot be empty.');
        }

        $values = [];
        $wildcard = $expression === '*';
        foreach (explode(',', $expression) as $part) {
            self::expandPart(trim($part), $minimum, $maximum, $dayOfWeek, $values);
        }

        if ($values === []) {
            throw new InvalidArgumentException('Cron field resolves to no values.');
        }

        return new self($wildcard, $values, $dayOfWeek);
    }

    public function matches(int $value): bool
    {
        if ($this->dayOfWeek && $value === 7) {
            $value = 0;
        }

        return isset($this->values[$value]);
    }

    public function isWildcard(): bool
    {
        return $this->wildcard;
    }

    /** @param array<int, true> $values */
    private static function expandPart(
        string $part,
        int $minimum,
        int $maximum,
        bool $dayOfWeek,
        array &$values,
    ): void {
        if ($part === '') {
            throw new InvalidArgumentException('Cron field contains an empty list item.');
        }

        $step = 1;
        $base = $part;
        if (str_contains($part, '/')) {
            [$base, $stepRaw] = array_pad(explode('/', $part, 2), 2, '');
            if ($stepRaw === '' || !ctype_digit($stepRaw) || (int) $stepRaw < 1) {
                throw new InvalidArgumentException('Cron step is invalid.');
            }
            $step = (int) $stepRaw;
        }

        if ($base === '*') {
            $start = $minimum;
            $end = $maximum;
        } elseif (str_contains($base, '-')) {
            [$startRaw, $endRaw] = array_pad(explode('-', $base, 2), 2, '');
            if (!ctype_digit($startRaw) || !ctype_digit($endRaw)) {
                throw new InvalidArgumentException('Cron range is invalid.');
            }
            $start = (int) $startRaw;
            $end = (int) $endRaw;
        } else {
            if (!ctype_digit($base)) {
                throw new InvalidArgumentException('Cron value is invalid.');
            }
            $start = (int) $base;
            $end = $start;
        }

        $allowedMaximum = $dayOfWeek ? max($maximum, 7) : $maximum;
        if ($start < $minimum || $end > $allowedMaximum || $start > $end) {
            throw new InvalidArgumentException('Cron value is outside the allowed range.');
        }

        for ($value = $start; $value <= $end; $value += $step) {
            $normalized = $dayOfWeek && $value === 7 ? 0 : $value;
            if ($normalized < $minimum || $normalized > $maximum) {
                throw new InvalidArgumentException('Cron value is outside the normalized range.');
            }
            $values[$normalized] = true;
        }
    }
}
