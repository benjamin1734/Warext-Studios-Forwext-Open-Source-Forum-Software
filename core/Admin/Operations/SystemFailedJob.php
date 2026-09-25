<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Operations;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SystemFailedJob
{
    public function __construct(
        public string $jobId,
        public string $queueName,
        public string $jobType,
        public int $attempts,
        public int $maxAttempts,
        public string $failureCode,
        public DateTimeImmutable $failedAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->jobId) !== 1) {
            throw new InvalidArgumentException('Failed job id is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $this->queueName) !== 1) {
            throw new InvalidArgumentException('Failed job queue name is invalid.');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $this->jobType) !== 1) {
            throw new InvalidArgumentException('Failed job type is invalid.');
        }
        if ($this->attempts < 0 || $this->maxAttempts < 1 || $this->attempts > $this->maxAttempts) {
            throw new InvalidArgumentException('Failed job attempt metadata is invalid.');
        }
        if (preg_match('/^[a-z0-9._-]{1,64}$/D', $this->failureCode) !== 1) {
            throw new InvalidArgumentException('Failed job failure code is invalid.');
        }
    }
}
