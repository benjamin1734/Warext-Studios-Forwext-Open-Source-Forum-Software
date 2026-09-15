<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\State;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\State\DatabaseDiscussionStateRepository;
use Forwext\Core\Forum\State\DraftConflictException;
use Forwext\Core\Forum\State\DraftTargetType;
use Forwext\Core\Forum\State\WatchNotificationMode;
use PHPUnit\Framework\TestCase;

final class DatabaseDiscussionStateRepositoryTest extends TestCase
{
    public function testAutosaveUsesRowLockAndAdvancesRevision(): void
    {
        $db = new DiscussionStateRecordingDatabase();
        $db->fetchOneQueue = [null];
        $draft = (new DatabaseDiscussionStateRepository($db))->saveDraft(
            $this->id('1'), DraftTargetType::NewThread, $this->id('a'), 'Title', 'Body', 0,
            $this->time('2026-09-15 22:00:00.000000'),
        );
        self::assertTrue($db->transactionUsed);
        self::assertSame(1, $draft->revision());
        self::assertTrue($db->fetchOneQueries[0]->requiresTransaction);
        self::assertStringContainsString('FOR UPDATE', $db->fetchOneQueries[0]->sql);
        self::assertStringStartsWith('INSERT INTO `forwext_content_drafts`', $db->executedQueries[0]->sql);
    }

    public function testStaleAutosaveRevisionFailsClosed(): void
    {
        $db = new DiscussionStateRecordingDatabase();
        $db->fetchOneQueue = [['revision' => 3]];
        $this->expectException(DraftConflictException::class);
        (new DatabaseDiscussionStateRepository($db))->saveDraft(
            $this->id('1'), DraftTargetType::Reply, $this->id('b'), null, 'Stale body', 2,
            $this->time('2026-09-15 22:00:00.000000'),
        );
    }

    public function testThreadReadPositionUsesMonotonicUpsert(): void
    {
        $db = new DiscussionStateRecordingDatabase();
        $db->fetchValueQueue = [5];
        (new DatabaseDiscussionStateRepository($db))->markThreadRead(
            $this->id('1'), $this->id('b'), 4, $this->time('2026-09-15 22:05:00.000000'),
        );
        self::assertStringContainsString('GREATEST(`last_read_post_position`', $db->executedQueries[0]->sql);
        self::assertSame(4, $db->executedQueries[0]->parameters['position']);
    }

    public function testForumWatermarkSuppressesOnlyOlderVisiblePostActivity(): void
    {
        $db = new DiscussionStateRecordingDatabase();
        $db->fetchOneQueue = [[
            'latest_position' => 5,
            'latest_activity_at' => '2026-09-15 22:00:00.000000',
            'read_position' => 2,
            'forum_marked_at' => '2026-09-15 22:01:00.000000',
        ]];
        $repo = new DatabaseDiscussionStateRepository($db);
        self::assertFalse($repo->isThreadUnread($this->id('1'), $this->id('b')));

        $db->fetchOneQueue = [[
            'latest_position' => 6,
            'latest_activity_at' => '2026-09-15 22:02:00.000000',
            'read_position' => 2,
            'forum_marked_at' => '2026-09-15 22:01:00.000000',
        ]];
        self::assertTrue($repo->isThreadUnread($this->id('1'), $this->id('b')));
        self::assertStringContainsString('MAX(`p2`.`updated_at_utc`)', $db->fetchOneQueries[0]->sql);
    }

    public function testMissingSubscriptionPreferencesUseSafeDefaults(): void
    {
        $db = new DiscussionStateRecordingDatabase();
        $db->fetchOneQueue = [null];
        $preferences = (new DatabaseDiscussionStateRepository($db))->subscriptionPreferences($this->id('1'));
        self::assertTrue($preferences->autoWatchCreatedThreads());
        self::assertSame(WatchNotificationMode::InApp, $preferences->defaultThreadMode());
    }

    private function id(string $seed): EntityId { return EntityId::fromString(str_repeat($seed, 32)); }
    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class DiscussionStateRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */ public array $executedQueries = [];
    /** @var list<CompiledQuery> */ public array $fetchOneQueries = [];
    /** @var list<array<string,mixed>|null> */ public array $fetchOneQueue = [];
    /** @var list<mixed> */ public array $fetchValueQueue = [];
    public bool $transactionUsed = false;
    private bool $inside = false;

    public function execute(CompiledQuery $query): int { $this->executedQueries[] = $query; return 1; }
    public function fetchOne(CompiledQuery $query): ?array { $this->fetchOneQueries[] = $query; return array_shift($this->fetchOneQueue); }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return array_shift($this->fetchValueQueue) ?? 0; }
    public function inTransaction(): bool { return $this->inside; }
    public function transaction(Closure $callback): mixed
    {
        $this->transactionUsed = true;
        $previous = $this->inside;
        $this->inside = true;
        try { return $callback($this); } finally { $this->inside = $previous; }
    }
}
