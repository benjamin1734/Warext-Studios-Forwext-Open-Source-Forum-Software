<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Attachment;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentFilename;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Forum\Attachment\AttachmentRecord;
use Forwext\Core\Forum\Attachment\AttachmentState;
use Forwext\Core\Forum\Attachment\DatabaseAttachmentRepository;
use PHPUnit\Framework\TestCase;

final class DatabaseAttachmentRepositoryTest extends TestCase
{
    public function testTemporaryInsertLocksOwnerAndRechecksQuotaBeforeInsert(): void
    {
        $database = new AttachmentRepositoryRecordingDatabase();
        $database->fetchOneQueue = [
            ['user_id' => str_repeat('1', 32)],
            ['temporary_count' => 0, 'temporary_bytes' => 0, 'stored_bytes' => 0],
        ];
        $repository = new DatabaseAttachmentRepository($database, new AttachmentQuotaPolicy());
        $record = $this->record();

        $repository->createTemporary($record);

        self::assertCount(2, $database->fetchOneQueries);
        self::assertStringContainsString('FOR UPDATE', $database->fetchOneQueries[0]->sql);
        self::assertStringContainsString('forwext_users', $database->fetchOneQueries[0]->sql);
        self::assertCount(1, $database->executedQueries);
        self::assertStringStartsWith('INSERT INTO `forwext_attachments`', $database->executedQueries[0]->sql);
        self::assertStringNotContainsString($record->filename->value(), $database->executedQueries[0]->sql);
        self::assertSame($record->filename->value(), $database->executedQueries[0]->parameters['filename']);
    }

    public function testConcurrentQuotaReservationFailsBeforeInsert(): void
    {
        $database = new AttachmentRepositoryRecordingDatabase();
        $database->fetchOneQueue = [
            ['user_id' => str_repeat('1', 32)],
            ['temporary_count' => 20, 'temporary_bytes' => 0, 'stored_bytes' => 0],
        ];
        $repository = new DatabaseAttachmentRepository($database, new AttachmentQuotaPolicy());

        try {
            $repository->createTemporary($this->record());
            self::fail('Expected quota rejection.');
        } catch (AttachmentOperationException) {
            self::assertCount(0, $database->executedQueries);
        }
    }

    public function testFinalizeUsesOwnerAndTemporaryStatePredicate(): void
    {
        $database = new AttachmentRepositoryRecordingDatabase();
        $repository = new DatabaseAttachmentRepository($database);
        $record = $this->record();

        $repository->finalize(
            $record->attachmentId,
            $record->ownerUserId,
            $this->id('c'),
            'attachments/a/c/d/final.txt',
            null,
            $this->time('2026-09-15 22:01:00.000000'),
        );

        self::assertCount(1, $database->executedQueries);
        self::assertStringContainsString("`state` = 'temporary'", $database->executedQueries[0]->sql);
        self::assertStringContainsString('`owner_user_id` = :owner_user_id', $database->executedQueries[0]->sql);
    }

    public function testUsageAndCleanupQueriesAreBoundedAndParameterized(): void
    {
        $database = new AttachmentRepositoryRecordingDatabase();
        $database->fetchOneQueue[] = ['temporary_count' => 2, 'temporary_bytes' => 300, 'stored_bytes' => 900];
        $repository = new DatabaseAttachmentRepository($database);
        $usage = $repository->usageForUser($this->id('1'));
        $database->fetchAllQueue[] = [];
        $repository->expiredTemporary($this->time('2026-09-16 00:00:00.000000'), 75);

        self::assertSame(2, $usage->temporaryCount);
        self::assertSame(900, $usage->storedBytes);
        self::assertStringContainsString("CASE WHEN `state` = 'temporary'", $database->fetchOneQueries[0]->sql);
        self::assertStringContainsString("`state` = 'temporary'", $database->fetchAllQueries[0]->sql);
        self::assertStringContainsString('LIMIT 75', $database->fetchAllQueries[0]->sql);
        self::assertArrayHasKey('before', $database->fetchAllQueries[0]->parameters);
    }

    private function record(): AttachmentRecord
    {
        return new AttachmentRecord(
            $this->id('d'), $this->id('1'), $this->id('a'), null,
            AttachmentFilename::fromClient('notes.txt'), 'text/plain', 'txt', 5,
            str_repeat('a', 64), 'attachments/tmp/1/d/source.txt', null, null, null, false,
            AttachmentState::Temporary,
            $this->time('2026-09-15 22:00:00.000000'),
            $this->time('2026-09-16 22:00:00.000000'), null,
        );
    }

    private function id(string $seed): EntityId { return EntityId::fromString(str_repeat($seed, 32)); }
    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class AttachmentRepositoryRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */ public array $executedQueries = [];
    /** @var list<CompiledQuery> */ public array $fetchOneQueries = [];
    /** @var list<CompiledQuery> */ public array $fetchAllQueries = [];
    /** @var list<array<string,mixed>|null> */ public array $fetchOneQueue = [];
    /** @var list<list<array<string,mixed>>> */ public array $fetchAllQueue = [];
    public int $executeResult = 1;
    public function execute(CompiledQuery $query): int { $this->executedQueries[] = $query; return $this->executeResult; }
    public function fetchOne(CompiledQuery $query): ?array { $this->fetchOneQueries[] = $query; return array_shift($this->fetchOneQueue); }
    public function fetchAll(CompiledQuery $query): array { $this->fetchAllQueries[] = $query; return array_shift($this->fetchAllQueue) ?? []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { return $callback($this); }
}
