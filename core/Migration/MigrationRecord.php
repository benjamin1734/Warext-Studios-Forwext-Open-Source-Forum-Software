<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use DateTimeImmutable;

final readonly class MigrationRecord
{
    public function __construct(
        public string $migrationKey,
        public MigrationOwner $owner,
        public MigrationId $id,
        public string $checksum,
        public MigrationStatus $status,
        public int $batch,
        public int $attempt,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt = null,
        public ?int $durationMs = null,
        public ?string $failureCode = null,
    ) {
    }
}
