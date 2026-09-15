<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateDiscussionStateTables;
use PHPUnit\Framework\TestCase;

final class DiscussionStateMigrationTest extends TestCase
{
    public function testMigrationCreatesDraftReadWatchAndPreferenceTables(): void
    {
        $database = new DiscussionStateMigrationRecordingDatabase();
        $migration = new CreateDiscussionStateTables();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260915235959_discussion_state', $migration->id()->value());
        self::assertCount(6, $database->executedQueries);
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        self::assertStringContainsString('forwext_content_drafts', $sql);
        self::assertStringContainsString('forwext_thread_read_state', $sql);
        self::assertStringContainsString('forwext_forum_read_state', $sql);
        self::assertStringContainsString('forwext_watched_threads', $sql);
        self::assertStringContainsString('forwext_watched_forums', $sql);
        self::assertStringContainsString('forwext_subscription_preferences', $sql);
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testVerificationRequiresTablesForeignKeysAndCompositeDraftPrimaryKey(): void
    {
        $database = new DiscussionStateMigrationRecordingDatabase();
        $database->fetchValues = [6, 10, 3];

        $result = (new CreateDiscussionStateTables())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(3, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenDraftPrimaryKeyIsIncomplete(): void
    {
        $database = new DiscussionStateMigrationRecordingDatabase();
        $database->fetchValues = [6, 10, 2];

        $result = (new CreateDiscussionStateTables())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class DiscussionStateMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $verificationQueries = [];
    /** @var list<int> */
    public array $fetchValues = [];

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->verificationQueries[] = $query;
        return array_shift($this->fetchValues) ?? 0;
    }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { return $callback($this); }
}
