<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Moderation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\BulkThreadAction;
use Forwext\Core\Forum\Moderation\DatabaseContentModerationRepository;
use Forwext\Core\Forum\Moderation\DatabaseModerationAuditStore;
use Forwext\Core\Forum\Moderation\ModerationAuditContext;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Moderation\Oversight\ModerationOversightStore;
use Forwext\Core\Moderation\Oversight\OversightChainState;
use Forwext\Core\Moderation\Oversight\OversightEntry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseContentModerationRepositoryTest extends TestCase
{
    public function testMoveLocksThreadAndPersistsMutationAndAuditInOneTransaction(): void
    {
        $database = new ModerationRepositoryRecordingDatabase();
        $database->fetchOneQueue[] = $this->threadRow('a', 'b');
        $oversight = new ModerationRepositoryOversightStore();
        $repository = new DatabaseContentModerationRepository(
            $database,
            new DatabaseModerationAuditStore($database, $oversight),
        );

        $repository->moveThread(
            $this->id('a'),
            $this->id('c'),
            $this->context('req-move'),
        );

        self::assertTrue($database->transactionUsed);
        self::assertCount(1, $database->fetchOneQueries);
        self::assertStringContainsString('FOR UPDATE', $database->fetchOneQueries[0]->sql);
        self::assertCount(2, $database->executedQueries);
        self::assertStringContainsString('UPDATE `forwext_threads`', $database->executedQueries[0]->sql);
        self::assertSame($this->id('c')->value(), $database->executedQueries[0]->parameters['forum_node_id']);
        self::assertStringContainsString('INSERT INTO forwext_core_audit_events', $database->executedQueries[1]->sql);
        self::assertSame('moderation', $database->executedQueries[1]->parameters['scope']);
        self::assertSame('thread.move', $database->executedQueries[1]->parameters['action']);
        self::assertSame($this->id('1')->value(), $database->executedQueries[1]->parameters['actor_user_id']);
        self::assertSame(1, $oversight->appends);
    }

    public function testSplitRejectsMovingSourceFirstPostWithoutMutationOrAudit(): void
    {
        $database = new ModerationRepositoryRecordingDatabase();
        $database->fetchOneQueue[] = $this->threadRow('a', 'b');
        $database->fetchAllQueue[] = [
            $this->postRow('d', 1),
            $this->postRow('e', 2),
        ];
        $repository = new DatabaseContentModerationRepository(
            $database,
            new DatabaseModerationAuditStore($database, new ModerationRepositoryOversightStore()),
        );

        $this->expectException(RuntimeException::class);
        try {
            $repository->splitThread(
                $this->id('a'),
                [$this->id('d')],
                $this->id('c'),
                ThreadTitle::fromString('Invalid split'),
                $this->context('req-split'),
            );
        } finally {
            self::assertTrue($database->transactionUsed);
            self::assertCount(1, $database->fetchOneQueries);
            self::assertCount(1, $database->fetchAllQueries);
            self::assertStringContainsString('FOR UPDATE', $database->fetchAllQueries[0]->sql);
            self::assertSame([], $database->executedQueries);
        }
    }

    public function testBulkRejectMovesPendingThreadToRejectedWithPerItemAndSummaryAudit(): void
    {
        $database = new ModerationRepositoryRecordingDatabase();
        $row = $this->threadRow('a', 'b');
        $row['moderation_state'] = 'pending';
        $database->fetchOneQueue[] = $row;
        $repository = new DatabaseContentModerationRepository(
            $database,
            new DatabaseModerationAuditStore($database, new ModerationRepositoryOversightStore()),
        );

        $repository->bulkThreads(
            BulkThreadAction::Reject,
            [$this->id('a')],
            $this->context('req-bulk-reject'),
        );

        self::assertTrue($database->transactionUsed);
        self::assertCount(3, $database->executedQueries);
        self::assertSame('rejected', $database->executedQueries[0]->parameters['value']);
        self::assertSame('thread.reject', $database->executedQueries[1]->parameters['action']);
        self::assertSame('bulk.thread', $database->executedQueries[2]->parameters['action']);
        self::assertStringContainsString(
            '"action":"reject"',
            (string) $database->executedQueries[2]->parameters['after_json'],
        );
    }

    public function testBulkApprovalRejectsStaleThreadDecisionBeforeAnyMutation(): void
    {
        $database = new ModerationRepositoryRecordingDatabase();
        $database->fetchOneQueue[] = $this->threadRow('a', 'b');
        $repository = new DatabaseContentModerationRepository(
            $database,
            new DatabaseModerationAuditStore($database, new ModerationRepositoryOversightStore()),
        );

        $this->expectException(RuntimeException::class);
        try {
            $repository->bulkThreads(
                BulkThreadAction::Reject,
                [$this->id('a')],
                $this->context('req-stale-reject'),
            );
        } finally {
            self::assertTrue($database->transactionUsed);
            self::assertSame([], $database->executedQueries);
        }
    }

    public function testAuditStoreRefusesOutOfTransactionAppend(): void
    {
        $database = new ModerationRepositoryRecordingDatabase();
        $store = new DatabaseModerationAuditStore($database, new ModerationRepositoryOversightStore());

        $event = new \Forwext\Core\Forum\Moderation\ModerationAuditEvent(
            \Forwext\Core\Forum\Moderation\ModerationAuditEvent::generateId(),
            $this->id('1'),
            \Forwext\Core\Forum\Moderation\ModerationAuditAction::ThreadLock,
            'thread',
            $this->id('a')->value(),
            $this->id('b'),
            ModerationReasonCode::fromString('review'),
            ModerationRequestId::fromString('req-audit'),
            ['locked' => false],
            ['locked' => true],
            $this->time('2026-09-15 21:30:00.000000'),
        );

        $this->expectException(RuntimeException::class);
        $store->append($event);
    }

    /** @return array<string,mixed> */
    private function threadRow(string $threadSeed, string $forumSeed): array
    {
        return [
            'thread_id' => $this->id($threadSeed)->value(),
            'forum_node_id' => $this->id($forumSeed)->value(),
            'author_user_id' => $this->id('2')->value(),
            'type_key' => 'discussion',
            'title' => 'Stored thread',
            'moderation_state' => 'visible',
            'locked' => 0,
            'sticky' => 0,
            'featured' => 0,
            'version' => 3,
            'deleted' => 0,
            'deleted_at_utc' => null,
            'merged_into_thread_id' => null,
            'created_at_utc' => '2026-09-15 20:00:00.000000',
            'updated_at_utc' => '2026-09-15 20:00:00.000000',
        ];
    }

    /** @return array<string,mixed> */
    private function postRow(string $postSeed, int $position): array
    {
        return [
            'post_id' => $this->id($postSeed)->value(),
            'author_user_id' => $this->id('2')->value(),
            'position' => $position,
            'moderation_state' => 'visible',
            'deleted' => 0,
        ];
    }

    private function context(string $request): ModerationAuditContext
    {
        return new ModerationAuditContext(
            $this->id('1'),
            ModerationReasonCode::fromString('review'),
            ModerationRequestId::fromString($request),
            $this->time('2026-09-15 21:30:00.000000'),
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class ModerationRepositoryRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchOneQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];
    /** @var list<array<string,mixed>|null> */
    public array $fetchOneQueue = [];
    /** @var list<list<array<string,mixed>>> */
    public array $fetchAllQueue = [];
    /** @var list<mixed> */
    public array $fetchValueQueue = [];
    public bool $transactionUsed = false;
    private bool $inside = false;

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->fetchOneQueries[] = $query;
        return array_shift($this->fetchOneQueue);
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return array_shift($this->fetchAllQueue) ?? [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return array_shift($this->fetchValueQueue);
    }

    public function inTransaction(): bool
    {
        return $this->inside;
    }

    public function transaction(Closure $callback): mixed
    {
        $this->transactionUsed = true;
        $wasInside = $this->inside;
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = $wasInside;
        }
    }
}


final class ModerationRepositoryOversightStore implements ModerationOversightStore
{
    public int $appends = 0;

    public function append(ModerationAuditEvent $event): OversightEntry
    {
        $this->appends++;
        $hash = str_repeat('a', 64);
        return new OversightEntry(
            $this->appends,
            $event->auditId,
            $event->actorUserId,
            $event->action->value,
            $event->targetType,
            $event->targetId,
            $event->requestId->value(),
            '{}',
            $hash,
            str_repeat('0', 64),
            $hash,
            $event->occurredAt,
        );
    }

    public function findByAuditId(EntityId $auditId): ?OversightEntry { return null; }
    public function pageAfter(int $sequence, int $limit = 500): array { return []; }
    public function recent(int $limit = 100): array { return []; }
    public function chainState(): OversightChainState { return OversightChainState::genesis(); }
}
