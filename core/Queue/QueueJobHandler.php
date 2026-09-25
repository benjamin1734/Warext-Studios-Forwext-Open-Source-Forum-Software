<?php

declare(strict_types=1);

namespace Forwext\Core\Queue;

use DateTimeImmutable;

interface QueueJobHandler
{
    public function jobType(): string;

    public function handle(string $payload, DateTimeImmutable $now): int;
}
