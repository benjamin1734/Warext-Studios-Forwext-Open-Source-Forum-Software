<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use InvalidArgumentException;

final readonly class SupportSubmissionPolicy
{
    public function __construct(
        public int $descriptionMaxBytes = 10000,
        public int $maxAttachments = 5,
        public int $hourlyLimit = 5,
        public int $dailyLimit = 20,
        public int $duplicateLimit = 2,
        public int $duplicateWindowSeconds = 900,
    ) {
        foreach ([
            $this->descriptionMaxBytes,
            $this->maxAttachments,
            $this->hourlyLimit,
            $this->dailyLimit,
            $this->duplicateLimit,
            $this->duplicateWindowSeconds,
        ] as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Support submission policy values must be positive.');
            }
        }
        if ($this->duplicateWindowSeconds > 86400 || $this->maxAttachments > 20) {
            throw new InvalidArgumentException('Support submission policy is outside the supported range.');
        }
    }
}
