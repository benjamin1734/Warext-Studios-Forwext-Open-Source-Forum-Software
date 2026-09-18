<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use InvalidArgumentException;

final readonly class BugStaffSummary
{
    public function __construct(
        public int $total,
        public int $new,
        public int $inReview,
        public int $resolved,
        public int $rejected,
        public int $duplicate,
        public int $unassigned,
    ) {
        foreach ([
            $this->total,
            $this->new,
            $this->inReview,
            $this->resolved,
            $this->rejected,
            $this->duplicate,
            $this->unassigned,
        ] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Bug dashboard summary values cannot be negative.');
            }
        }
    }
}
