<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use Forwext\Core\Bug\Report\BugReport;
use InvalidArgumentException;

final readonly class BugDuplicateSuggestion
{
    public function __construct(
        public BugReport $report,
        public float $score,
    ) {
        if (!is_finite($this->score) || $this->score < 0.0 || $this->score > 1.0) {
            throw new InvalidArgumentException('Bug duplicate suggestion score must be between 0 and 1.');
        }
    }
}
