<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use InvalidArgumentException;

final readonly class BugReportFormPolicy
{
    public function __construct(
        public int $maxAttachments = 5,
        public int $maxFieldBytes = 10000,
    ) {
        if ($this->maxAttachments < 1 || $this->maxAttachments > 20 || $this->maxFieldBytes < 1) {
            throw new InvalidArgumentException('Bug report form policy is invalid.');
        }
    }
}
