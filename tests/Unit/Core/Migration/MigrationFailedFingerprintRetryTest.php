<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationEngine;
use Forwext\Core\Migration\MigrationFingerprint;
use Forwext\Core\Migration\MigrationHistoryStore;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationRecord;
use Forwext\Core\Migration\MigrationStatus;
use Forwext\Core\Migration\MigrationVerification;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigrationFailedFingerprintRetryTest extends TestCase
{
    public function testFailedMigrationCanRetryAfterItsSourceIsCorrected(): void
    {
        $migration = new CorrectedRetryMigration();
        $history = new RetryHistoryStore();
        $history->seed(new MigrationRecord(
            'core:core:' . $migration->id()->value(),
            $migration->owner(),
            $migration->id(),
            str_repeat('0', 64),
            MigrationStatus::Failed,
            1,
            1,
            new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-09-17 12:00:01', new DateTimeZone('UTC')),
            1000,
            'migration_failed',
        ));

        $checksum = MigrationFingerprint::calculate($migration);
        self::assertNotSame(str_repeat('0', 64), $checksum);

        $report = (new MigrationEngine(new RetryDatabase(), $history))->migrate([$migration]);

        self::assertSame(['core:core:' . $migration->id()->value()], $report->applied);
        self::assertSame(1, $migration->runs);
        $record = $history->find($migration->owner(), $migration->id());
        self::assertNotNull($record);
        self::assertSame(MigrationStatus::Applied, $record->status);
        self::assertSame(2, $record->attempt);
        self::assertSame($checksum, $record->checksum);
    }
}

final class CorrectedRetryMigration implements Migration
{
    public int $runs = 0;

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260917235959_retry_corrected');
    }

    public function owner(): MigrationOwner
    {
        return MigrationOwner::core();
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(MigrationContext $context): void
    {
        ++$this->runs;
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        return MigrationVerification::passed();
    }
}

final class RetryDatabase implements TransactionalQueryExecutor
{
    public function execute(CompiledQuery $query): int
    {
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return null;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback($this);
    }
}

final class RetryHistoryStore implements MigrationHistoryStore
{
    private ?MigrationRecord $record = null;

    public function initialize(): void
    {
    }

    public function seed(MigrationRecord $record): void
    {
        $this->record = $record;
    }

    public function find(MigrationOwner $owner, MigrationId $id): ?MigrationRecord
    {
        return $this->record;
    }

    public function nextBatch(): int
    {
        return 2;
    }

    public function markRunning(
        MigrationOwner $owner,
        MigrationId $id,
        string $checksum,
        int $batch,
        int $attempt,
        DateTimeImmutable $startedAt,
    ): void {
        $this->record = new MigrationRecord(
            $owner->key() . ':' . $id->value(),
            $owner,
            $id,
            $checksum,
            MigrationStatus::Running,
            $batch,
            $attempt,
            $startedAt,
        );
    }

    public function markApplied(
        MigrationOwner $owner,
        MigrationId $id,
        DateTimeImmutable $finishedAt,
        int $durationMs,
    ): void {
        $current = $this->record ?? throw new RuntimeException('Missing retry record.');
        $this->record = new MigrationRecord(
            $current->migrationKey,
            $current->owner,
            $current->id,
            $current->checksum,
            MigrationStatus::Applied,
            $current->batch,
            $current->attempt,
            $current->startedAt,
            $finishedAt,
            $durationMs,
        );
    }

    public function markFailed(
        MigrationOwner $owner,
        MigrationId $id,
        DateTimeImmutable $finishedAt,
        int $durationMs,
        string $failureCode,
    ): void {
        throw new RuntimeException('Corrected retry must not fail.');
    }
}
