<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use InvalidArgumentException;

final readonly class MigrationVerification
{
    /** @var list<string> */
    private array $failures;

    /** @param iterable<string> $failures */
    private function __construct(iterable $failures)
    {
        $normalized = [];
        foreach ($failures as $failure) {
            $failure = trim($failure);
            if ($failure !== '') {
                $normalized[] = $failure;
            }
        }
        $this->failures = $normalized;
    }

    public static function passed(): self
    {
        return new self([]);
    }

    public static function failed(string $failure, string ...$more): self
    {
        $result = new self([$failure, ...$more]);
        if ($result->failures === []) {
            throw new InvalidArgumentException('Failed migration verification requires at least one diagnostic.');
        }

        return $result;
    }

    public function isPassed(): bool
    {
        return $this->failures === [];
    }

    /** @return list<string> */
    public function failures(): array
    {
        return $this->failures;
    }
}
