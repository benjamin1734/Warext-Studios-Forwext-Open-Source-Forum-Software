<?php

declare(strict_types=1);

namespace Forwext\Core\Queue;

use DateTimeImmutable;

interface QueueDriver
{
    public function push(
        QueueName $queue,
        string $type,
        string $payload,
        int $maxAttempts = 3,
        ?DateTimeImmutable $availableAt = null,
    ): JobId;

    public function reserve(QueueName $queue, int $visibilityTimeoutSeconds = 60): ?QueueReservation;

    public function acknowledge(QueueReservation $reservation): void;

    public function retry(QueueReservation $reservation, int $delaySeconds = 0): void;

    public function fail(QueueReservation $reservation, string $failureCode): void;
}
