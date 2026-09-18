<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use InvalidArgumentException;

final readonly class BugReportFormPolicy
{
    public function __construct(
        public int $maxAttachments = 5,
        public int $reproductionMaxBytes = 10_000,
        public int $expectedMaxBytes = 5_000,
        public int $actualMaxBytes = 5_000,
        public int $sourcePathMaxBytes = 2_048,
    ) {
        foreach ([
            $maxAttachments,
            $reproductionMaxBytes,
            $expectedMaxBytes,
            $actualMaxBytes,
            $sourcePathMaxBytes,
        ] as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Bug report form policy values must be positive.');
            }
        }
    }
}
