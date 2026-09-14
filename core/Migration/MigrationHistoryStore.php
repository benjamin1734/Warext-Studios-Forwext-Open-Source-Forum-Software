<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use DateTimeImmutable;

interface MigrationHistoryStore
{
    public function initialize(): void;

    public function find(MigrationOwner $owner, MigrationId $id): ?MigrationRecord;

    public function nextBatch(): int;

    public function markRunning(
        MigrationOwner $owner,
        MigrationId $id,
        string $checksum,
        int $batch,
        int $attempt,
        DateTimeImmutable $startedAt,
    ): void;

    public function markApplied(
        MigrationOwner $owner,
        MigrationId $id,
        DateTimeImmutable $finishedAt,
        int $durationMs,
    ): void;

    public function markFailed(
        MigrationOwner $owner,
        MigrationId $id,
        DateTimeImmutable $finishedAt,
        int $durationMs,
        string $failureCode,
    ): void;
}
