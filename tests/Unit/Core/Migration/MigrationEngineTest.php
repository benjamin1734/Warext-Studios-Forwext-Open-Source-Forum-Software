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
use Forwext\Core\Migration\MigrationExecutionException;
use Forwext\Core\Migration\MigrationFingerprint;
use Forwext\Core\Migration\MigrationHistoryStore;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationIntegrityException;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationRecord;
use Forwext\Core\Migration\MigrationStatus;
use Forwext\Core\Migration\MigrationVerification;
use Forwext\Core\Migration\RecoverableMigration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class MigrationEngineTest extends TestCase
{
    public function testAppliesVersionedMigrationsThenSkipsAlreadyAppliedOnRetry(): void
    {
        $database = new FakeTransactionalExecutor();
        $history = new MemoryHistoryStore();
        $first = new ProbeMigration('20260914000100_first', MigrationOwner::core());
        $second = new ProbeMigration('20260914000200_second', MigrationOwner::module('support'));
        $engine = new MigrationEngine($database, $history);

        $report = $engine->migrate([$second, $first]);

        self::assertSame([
            'core:core:20260914000100_first',
            'module:support:20260914000200_second',
        ], $report->applied);
        self::assertSame(1, $first->runs);
        self::assertSame(1, $second->runs);

        $retry = $engine->migrate([$first, $second]);

        self::assertSame([], $retry->applied);
        self::assertCount(2, $retry->skipped);
        self::assertSame(1, $first->runs);
        self::assertSame(1, $second->runs);
    }

    public function testSameMigrationIdCanExistInDifferentOwnerScopes(): void
    {
        $database = new FakeTransactionalExecutor();
        $history = new MemoryHistoryStore();
        $id = '20260914000300_shared';
        $engine = new MigrationEngine($database, $history);

        $report = $engine->migrate([
            new ProbeMigration($id, MigrationOwner::core()),
            new ProbeMigration($id, MigrationOwner::module('marketplace')),
            new ProbeMigration($id, MigrationOwner::addon('vendor.example')),
        ]);

        self::assertCount(3, $report->applied);
    }

    public function testDuplicateMigrationKeyFailsBeforeExecution(): void
    {
        $migration = new ProbeMigration('20260914000400_duplicate', MigrationOwner::core());
        $engine = new MigrationEngine(new FakeTransactionalExecutor(), new MemoryHistoryStore());

        $this->expectException(MigrationIntegrityException::class);
        $engine->migrate([$migration, $migration]);
    }

    public function testNonIdempotentMigrationIsRejected(): void
    {
        $migration = new ProbeMigration('20260914000500_unsafe', MigrationOwner::core(), idempotent: false);
        $engine = new MigrationEngine(new FakeTransactionalExecutor(), new MemoryHistoryStore());

        $this->expectException(MigrationIntegrityException::class);
        $engine->migrate([$migration]);
    }

    public function testTransactionalMigrationUsesTransactionBoundary(): void
    {
        $database = new FakeTransactionalExecutor();
        $migration = new ProbeMigration('20260914000600_transactional', MigrationOwner::core(), transactional: true);
        $engine = new MigrationEngine($database, new MemoryHistoryStore());

        $engine->migrate([$migration]);

        self::assertSame(1, $database->transactions);
        self::assertSame(1, $migration->runs);
    }

    public function testVerificationFailureMarksFailedAndDoesNotBecomeApplied(): void
    {
        $history = new MemoryHistoryStore();
        $migration = new ProbeMigration(
            '20260914000700_verify',
            MigrationOwner::core(),
            verificationPasses: false,
        );
        $engine = new MigrationEngine(new FakeTransactionalExecutor(), $history);

        try {
            $engine->migrate([$migration]);
            self::fail('Expected migration failure.');
        } catch (MigrationExecutionException) {
            $record = $history->find($migration->owner(), $migration->id());
            self::assertNotNull($record);
            self::assertSame(MigrationStatus::Failed, $record->status);
            self::assertSame('migration_failed', $record->failureCode);
        }
    }

    public function testRecoverableFailureRunsRecoveryAndStoresSafeFailureCode(): void
    {
        $history = new MemoryHistoryStore();
        $migration = new RecoveringProbeMigration('20260914000800_recover', MigrationOwner::core());
        $engine = new MigrationEngine(new FakeTransactionalExecutor(), $history);

        try {
            $engine->migrate([$migration]);
            self::fail('Expected migration failure.');
        } catch (MigrationExecutionException) {
            self::assertSame(1, $migration->recoveries);
            $record = $history->find($migration->owner(), $migration->id());
            self::assertNotNull($record);
            self::assertSame('migration_failed_recovered', $record->failureCode);
        }
    }

    public function testFingerprintDriftBlocksPreviouslyRecordedMigration(): void
    {
        $database = new FakeTransactionalExecutor();
        $history = new MemoryHistoryStore();
        $migration = new ProbeMigration('20260914000900_fingerprint', MigrationOwner::core());
        $history->initialize();
        $history->seed(new MigrationRecord(
            'core:core:' . $migration->id()->value(),
            $migration->owner(),
            $migration->id(),
            str_repeat('0', 64),
            MigrationStatus::Applied,
            1,
            1,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        ));

        self::assertNotSame(str_repeat('0', 64), MigrationFingerprint::calculate($migration));
        $this->expectException(MigrationIntegrityException::class);
        (new MigrationEngine($database, $history))->migrate([$migration]);
    }
}

final class FakeTransactionalExecutor implements TransactionalQueryExecutor
{
    public int $transactions = 0;
    private bool $inTransaction = false;

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
        return $this->inTransaction;
    }

    public function transaction(Closure $callback): mixed
    {
        ++$this->transactions;
        $previous = $this->inTransaction;
        $this->inTransaction = true;

        try {
            return $callback($this);
        } finally {
            $this->inTransaction = $previous;
        }
    }
}

final class MemoryHistoryStore implements MigrationHistoryStore
{
    /** @var array<string, MigrationRecord> */
    private array $records = [];
    private int $batch = 0;

    public function initialize(): void
    {
    }

    public function seed(MigrationRecord $record): void
    {
        $this->records[$record->migrationKey] = $record;
    }

    public function find(MigrationOwner $owner, MigrationId $id): ?MigrationRecord
    {
        return $this->records[$owner->key() . ':' . $id->value()] ?? null;
    }

    public function nextBatch(): int
    {
        return ++$this->batch;
    }

    public function markRunning(
        MigrationOwner $owner,
        MigrationId $id,
        string $checksum,
        int $batch,
        int $attempt,
        DateTimeImmutable $startedAt,
    ): void {
        $key = $owner->key() . ':' . $id->value();
        $this->records[$key] = new MigrationRecord(
            $key,
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
        $this->finish($owner, $id, MigrationStatus::Applied, $finishedAt, $durationMs, null);
    }

    public function markFailed(
        MigrationOwner $owner,
        MigrationId $id,
        DateTimeImmutable $finishedAt,
        int $durationMs,
        string $failureCode,
    ): void {
        $this->finish($owner, $id, MigrationStatus::Failed, $finishedAt, $durationMs, $failureCode);
    }

    private function finish(
        MigrationOwner $owner,
        MigrationId $id,
        MigrationStatus $status,
        DateTimeImmutable $finishedAt,
        int $durationMs,
        ?string $failureCode,
    ): void {
        $key = $owner->key() . ':' . $id->value();
        $current = $this->records[$key] ?? throw new RuntimeException('Missing migration history record.');
        $this->records[$key] = new MigrationRecord(
            $key,
            $current->owner,
            $current->id,
            $current->checksum,
            $status,
            $current->batch,
            $current->attempt,
            $current->startedAt,
            $finishedAt,
            $durationMs,
            $failureCode,
        );
    }
}

class ProbeMigration implements Migration
{
    public int $runs = 0;

    public function __construct(
        private readonly string $migrationId,
        private readonly MigrationOwner $migrationOwner,
        private readonly bool $idempotent = true,
        private readonly bool $transactional = false,
        private readonly bool $verificationPasses = true,
    ) {
    }

    public function id(): MigrationId
    {
        return MigrationId::fromString($this->migrationId);
    }

    public function owner(): MigrationOwner
    {
        return $this->migrationOwner;
    }

    public function isIdempotent(): bool
    {
        return $this->idempotent;
    }

    public function isTransactional(): bool
    {
        return $this->transactional;
    }

    public function up(MigrationContext $context): void
    {
        ++$this->runs;
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        return $this->verificationPasses
            ? MigrationVerification::passed()
            : MigrationVerification::failed('fixture verification failed');
    }
}

final class RecoveringProbeMigration extends ProbeMigration implements RecoverableMigration
{
    public int $recoveries = 0;

    public function up(MigrationContext $context): void
    {
        parent::up($context);
        throw new RuntimeException('fixture failure');
    }

    public function recover(MigrationContext $context, Throwable $failure): void
    {
        ++$this->recoveries;
    }
}
