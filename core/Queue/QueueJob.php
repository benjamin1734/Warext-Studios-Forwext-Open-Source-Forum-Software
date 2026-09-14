<?php

declare(strict_types=1);

namespace Forwext\Core\Queue;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class QueueJob
{
    public function __construct(
        public JobId $id,
        public QueueName $queue,
        public string $type,
        public string $payload,
        public int $attempts,
        public int $maxAttempts,
        public DateTimeImmutable $availableAt,
        public DateTimeImmutable $createdAt,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $type) !== 1) {
            throw new InvalidArgumentException('Queue job type is invalid.');
        }
        if ($attempts < 0 || $maxAttempts < 1 || $maxAttempts > 100 || $attempts > $maxAttempts) {
            throw new InvalidArgumentException('Queue job attempt metadata is invalid.');
        }
    }
}
